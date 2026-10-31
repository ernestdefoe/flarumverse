<?php

namespace ErnestDefoe\Flarumverse;

use Carbon\Carbon;
use ErnestDefoe\Flarumverse\Http\Remote;
use Flarum\Discussion\Discussion;
use Flarum\Foundation\ValidationException;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Guest;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * The opt-in directory of Flarum forums.
 *
 * Every forum can ask to be listed; one forum (ernestdefoe.online, or whatever
 * the hidden `directory_url` setting names) hosts the list. The host only
 * lists: pairing and sharing never pass through it.
 */
class Directory
{
    public const HOST = 'https://ernestdefoe.online';

    /** Days a listed forum may be unreachable before it's hidden. */
    public const HIDE_AFTER_DAYS = 14;

    public const TOPICS = [
        'general', 'gaming', 'sports', 'technology', 'development', 'science', 'entertainment', 'music',
        'books', 'art', 'hobbies', 'home', 'food', 'travel', 'cars', 'health', 'business', 'education',
        'society', 'faith', 'support', 'other',
    ];

    public const LANGUAGES = [
        'en', 'es', 'pt', 'fr', 'de', 'it', 'nl', 'pl', 'ru', 'uk', 'tr', 'ar', 'fa', 'he', 'hi', 'id', 'vi',
        'th', 'zh', 'ja', 'ko', 'sv', 'da', 'nb', 'fi', 'cs', 'ro', 'hu', 'el', 'mul',
    ];

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Identity $identity,
        protected Remote $remote,
        protected Threads $threads,
        protected Cache $cache,
    ) {
    }

    public function host(): string
    {
        return Address::normalise((string) $this->settings->get(Identity::PREFIX.'directory_url', '')) ?? self::HOST;
    }

    public function isHost(): bool
    {
        return $this->host() === $this->identity->url();
    }

    // ---- This forum's listing -------------------------------------------------

    public function listed(): bool
    {
        return (bool) $this->settings->get(Identity::PREFIX.'directory_listed');
    }

    /** @return array<string,mixed> what the admin page shows */
    public function state(): array
    {
        return [
            'host' => $this->host(),
            'isHost' => $this->isHost(),
            'listed' => $this->listed(),
            'topic' => (string) $this->settings->get(Identity::PREFIX.'directory_topic', ''),
            'language' => (string) $this->settings->get(Identity::PREFIX.'directory_language', ''),
            'status' => (string) $this->settings->get(Identity::PREFIX.'directory_status', ''),
            'logoBg' => $this->logoBgChoice(),
            'logo' => $this->identity->asset('logo_path'),
            'brand' => $this->brand(),
            'topics' => self::TOPICS,
            'languages' => self::LANGUAGES,
        ];
    }

    /** Opt in or out. Sends the request to the host and remembers its answer. */
    public function save(bool $listed, string $topic, string $language, string $logoBg = 'light'): void
    {
        $this->settings->set(Identity::PREFIX.'directory_logo_bg', in_array($logoBg, ['light', 'dark', 'brand'], true) ? $logoBg : 'light');
        if ($listed && (! in_array($topic, self::TOPICS, true) || ! in_array($language, self::LANGUAGES, true))) {
            throw new ValidationException(['directory' => $this->trans('directory_pick')]);
        }

        $this->settings->set(Identity::PREFIX.'directory_topic', $topic);
        $this->settings->set(Identity::PREFIX.'directory_language', $language);
        $this->settings->set(Identity::PREFIX.'directory_listed', $listed ? '1' : '0');
        $this->cache->forget('flarumverse:profile');

        if ($this->isHost()) {
            $listed ? $this->join($this->selfAbout(), Listing::query()->where('url', $this->identity->url())->first())
                : Listing::query()->where('url', $this->identity->url())->delete();
            $this->setStatus($listed ? $this->ownStatus() : '');

            return;
        }

        [$code, $reply] = $this->remote->send($this->host(), ['type' => $listed ? 'directory-join' : 'directory-leave']);
        if ($listed && ($code < 200 || $code >= 300)) {
            $this->settings->set(Identity::PREFIX.'directory_listed', '0');
            throw new ValidationException(['directory' => $this->trans('directory_failed')]);
        }
        $this->setStatus($listed ? (string) ($reply['status'] ?? Listing::PENDING) : '');
    }

    /** light | dark | brand; by default whatever the forum's own header uses. */
    private function logoBgChoice(): string
    {
        $saved = (string) $this->settings->get(Identity::PREFIX.'directory_logo_bg', '');

        return in_array($saved, ['light', 'dark', 'brand'], true) ? $saved : ($this->settings->get('theme_colored_header') ? 'brand' : 'light');
    }

    private function brand(): ?string
    {
        $color = (string) $this->settings->get('theme_primary_color');

        return preg_match('/^#[0-9a-f]{6}$/i', $color) ? strtolower($color) : null;
    }

    public function setStatus(string $status): void
    {
        if (in_array($status, ['', Listing::PENDING, Listing::APPROVED, Listing::REJECTED, 'removed'], true)) {
            $this->settings->set(Identity::PREFIX.'directory_status', $status);
        }
    }

    /**
     * What the about document adds when this forum is listed: public numbers and
     * the three newest discussion titles. Cached for 10 minutes.
     *
     * @return array<string,mixed>|null
     */
    public function profile(): ?array
    {
        if (! $this->listed()) {
            return null;
        }

        return $this->cache->remember('flarumverse:profile', 600, function () {
            $visible = Discussion::whereVisibleTo(new Guest())->where('discussions.is_private', false)->whereNull('discussions.hidden_at');

            return [
                'topic' => (string) $this->settings->get(Identity::PREFIX.'directory_topic'),
                'logoBackground' => match ($this->logoBgChoice()) {
                    'brand' => $this->brand() ?? 'light',
                    'dark' => 'dark',
                    default => 'light',
                },
                'language' => (string) $this->settings->get(Identity::PREFIX.'directory_language'),
                'members' => User::query()->where('is_email_confirmed', true)->count(),
                'discussions' => (clone $visible)->count(),
                'lastActivity' => ($last = (clone $visible)->max('discussions.last_posted_at')) ? Carbon::parse($last)->toIso8601String() : null,
                'latest' => array_map(fn ($t) => ['title' => $t['title'], 'url' => $t['url']], $this->threads->latest(3)),
            ];
        });
    }

    // ---- The host's side -------------------------------------------------------

    /**
     * A forum asked to be listed (or changed its topic or language).
     *
     * @param array{url:string,name:string,description:string,publicKey:string,directory:array<string,mixed>|null} $about
     * @return array<string,string>
     */
    public function join(array $about, ?Listing $listing): array
    {
        $profile = $about['directory'];
        if (! is_array($profile) || ! in_array($profile['topic'] ?? null, self::TOPICS, true)
            || ! in_array($profile['language'] ?? null, self::LANGUAGES, true)) {
            return ['error' => 'not_listed'];
        }
        if ($listing?->status === Listing::REJECTED && $listing->updated_at->gt(Carbon::now()->subDays(30))) {
            return ['error' => 'rejected'];
        }

        $listing ??= new Listing();
        if (! $listing->exists || $listing->status === Listing::REJECTED) {
            $listing->status = Listing::PENDING;
        }
        $listing->url = $about['url'];
        $listing->public_key = $about['publicKey'];
        $this->fill($listing, $about);
        $listing->save();

        return ['status' => $listing->status];
    }

    public function decide(Listing $listing, string $status): void
    {
        $listing->status = $status;
        $listing->save();
        $this->tell($listing, $status);
    }

    public function remove(Listing $listing): void
    {
        $listing->delete();
        $this->tell($listing, 'removed');
    }

    /** Daily: fresh numbers for every listing; gone when a forum opted out. */
    public function refresh(Listing $listing): string
    {
        $about = $listing->url === $this->identity->url() ? $this->selfAbout() : $this->remote->about($listing->url);
        $listing->checked_at = Carbon::now();

        if ($about === null) {
            $listing->unreachable_since ??= Carbon::now();
            $listing->save();

            return 'unreachable';
        }
        if ($about['directory'] === null || $about['publicKey'] !== $listing->public_key) {
            $listing->delete(); // opted out, or a different forum now answers at this address

            return 'removed';
        }

        $listing->unreachable_since = null;
        $this->fill($listing, $about);
        $listing->save();

        return 'ok';
    }

    /** @param array{name:string,description:string,logo?:string|null,icon?:string|null,directory:array<string,mixed>|null} $about */
    private function fill(Listing $listing, array $about): void
    {
        $p = (array) $about['directory'];
        $latest = [];
        foreach (array_slice(is_array($p['latest'] ?? null) ? $p['latest'] : [], 0, 3) as $t) {
            $title = Threads::text($t['title'] ?? null, 200);
            $url = is_string($t['url'] ?? null) ? $t['url'] : '';
            if ($title !== null && strlen($url) <= 400 && Address::belongsTo($url, $listing->url)) {
                $latest[] = ['title' => $title, 'url' => $url];
            }
        }

        // Shown as <img> on the directory page: only https images on the forum's own address.
        $image = fn ($u) => is_string($u) && strlen($u) <= 400 && Address::belongsTo($u, $listing->url)
            && preg_match('#\.(png|jpe?g|gif|webp|avif|svg|ico)(\?[^/]*)?$#i', $u) ? $u : null;
        $listing->logo = $image($about['logo'] ?? null);
        $listing->icon = $image($about['icon'] ?? null);
        $bg = (string) ($p['logoBackground'] ?? 'light');
        $listing->logo_bg = in_array($bg, ['light', 'dark'], true) || preg_match('/^#[0-9a-f]{6}$/', $bg) ? $bg : 'light';
        $listing->name = Threads::text($about['name'], 100) ?? (string) parse_url($listing->url, PHP_URL_HOST);
        $listing->description = Threads::text($about['description'], 300);
        $listing->topic = in_array($p['topic'] ?? null, self::TOPICS, true) ? $p['topic'] : 'other';
        $listing->language = in_array($p['language'] ?? null, self::LANGUAGES, true) ? $p['language'] : 'mul';
        $listing->members = min(max(0, (int) ($p['members'] ?? 0)), 100000000);
        $listing->discussions = min(max(0, (int) ($p['discussions'] ?? 0)), 100000000);

        try {
            $last = isset($p['lastActivity']) ? Carbon::parse((string) $p['lastActivity']) : null;
        } catch (\Throwable) {
            $last = null;
        }
        $listing->last_activity_at = $last?->isFuture() ? Carbon::now() : $last;
        $listing->latest = json_encode($latest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
    }

    /** Let the forum's admin page know what happened to its listing. */
    private function tell(Listing $listing, string $status): void
    {
        if ($listing->url === $this->identity->url()) {
            $this->setStatus($status === 'removed' ? 'removed' : $status);

            return;
        }
        $this->remote->send($listing->url, ['type' => 'directory-status', 'status' => $status]);
    }

    private function ownStatus(): string
    {
        return (string) (Listing::query()->where('url', $this->identity->url())->value('status') ?? Listing::PENDING);
    }

    /** @return array{url:string,name:string,description:string,publicKey:string,logo:string|null,icon:string|null,directory:array<string,mixed>|null} */
    private function selfAbout(): array
    {
        $this->cache->forget('flarumverse:profile');

        return [
            'url' => $this->identity->url(),
            'name' => $this->identity->name(),
            'description' => $this->identity->description(),
            'publicKey' => $this->identity->publicKey(),
            'logo' => $this->identity->asset('logo_path'),
            'icon' => $this->identity->asset('favicon_path'),
            'color' => $this->identity->color(),
            'directory' => $this->profile(),
        ];
    }

    /** The host's public key, for checking its status messages. */
    public function hostKey(): ?string
    {
        $key = $this->cache->get('flarumverse:hostkey:'.sha1($this->host()));
        if (is_string($key)) {
            return $key;
        }
        $about = $this->remote->about($this->host());
        if ($about !== null) {
            $this->cache->put('flarumverse:hostkey:'.sha1($this->host()), $about['publicKey'], 3600);
        }

        return $about['publicKey'] ?? null;
    }

    /** @param array<string,mixed> $params */
    private function trans(string $key, array $params = []): string
    {
        return resolve('translator')->trans('ernestdefoe-flarumverse.api.'.$key, $params);
    }
}
