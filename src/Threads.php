<?php

namespace ErnestDefoe\Flarumverse;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Http\UrlGenerator;
use Flarum\Post\CommentPost;
use Flarum\User\Guest;
use Illuminate\Database\ConnectionResolverInterface;
use s9e\TextFormatter\Utils;

/**
 * The two halves of sharing: what this forum sends (only what a guest could
 * read), and checking what a partner sends before it is stored (all of it is
 * untrusted text).
 */
class Threads
{
    public const LIMIT = 50;

    public function __construct(
        protected UrlGenerator $url,
        protected ExtensionManager $extensions,
        protected ConnectionResolverInterface $db,
    ) {
    }

    /** @return list<array<string,mixed>> this forum's latest shared discussions */
    public function latest(int $limit = self::LIMIT): array
    {
        return array_map([$this, 'payload'], $this->shared()->latest('discussions.created_at')->limit($limit)->get()->all());
    }

    /** @return array<string,mixed>|null one discussion, if a guest could read it */
    public function one(int $id): ?array
    {
        $discussion = $this->shared()->whereKey($id)->first();

        return $discussion ? $this->payload($discussion) : null;
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Discussion> */
    private function shared()
    {
        $tags = $this->extensions->isEnabled('flarum-tags');

        return Discussion::whereVisibleTo(new Guest())
            ->where('discussions.is_private', false)
            ->whereNull('discussions.hidden_at')
            ->with(array_merge(['firstPost', 'user'], $tags ? ['tags'] : []));
    }

    /** @return array<string,mixed> */
    private function payload(Discussion $discussion): array
    {
        $tag = null;
        if ($this->extensions->isEnabled('flarum-tags')) {
            $tags = $discussion->getRelation('tags');
            $primary = $tags->first(fn ($t) => $t->position !== null && $t->parent_id === null) ?? $tags->first();
            $tag = $primary?->name;
        }

        $post = $discussion->firstPost;

        return [
            'id' => (int) $discussion->id,
            'title' => (string) $discussion->title,
            'excerpt' => $post instanceof CommentPost ? self::excerpt((string) $post->getParsedContentAttribute()) : null,
            'author' => $discussion->user?->display_name,
            'tag' => $tag,
            'replies' => max(0, (int) $discussion->comment_count - 1),
            'url' => $this->url->to('forum')->route('discussion', ['id' => $discussion->id]),
            'postedAt' => $discussion->created_at?->toIso8601String(),
        ];
    }

    /** Plain text from stored post XML: formatting markers, quotes and code dropped. */
    public static function excerpt(string $xml): ?string
    {
        if ($xml === '') {
            return null;
        }
        // Quotes repeat someone else's words; code isn't a summary.
        $xml = preg_replace('#<(QUOTE|CODE|BLOCKQUOTE|IMG|UPL-IMAGE-PREVIEW)\b.*?</\1>#is', ' ', $xml) ?? $xml;
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(Utils::removeFormatting($xml)), ENT_QUOTES | ENT_HTML5)) ?? '');

        return $text === '' ? null : (mb_strlen($text) > 300 ? rtrim(mb_substr($text, 0, 299)).'…' : $text);
    }

    /**
     * One thread from a partner, checked, or null to ignore it.
     *
     * @param mixed $t
     * @return array<string,mixed>|null
     */
    public static function clean($t, Partner $partner): ?array
    {
        if (! is_array($t) || ! is_int($t['id'] ?? null) || $t['id'] < 1 || ! is_string($t['title'] ?? null)) {
            return null;
        }
        $url = (string) ($t['url'] ?? '');
        if (strlen($url) > 400 || ! Address::belongsTo($url, $partner->url)) {
            return null; // a partner links only to its own pages
        }
        $title = self::text($t['title'], 200);
        if ($title === null) {
            return null;
        }

        try {
            $posted = Carbon::parse((string) ($t['postedAt'] ?? 'now'));
        } catch (\Throwable) {
            $posted = Carbon::now();
        }

        return [
            'partner_id' => $partner->id,
            'remote_id' => $t['id'],
            'title' => $title,
            'excerpt' => self::text($t['excerpt'] ?? null, 300),
            'author' => self::text($t['author'] ?? null, 100),
            'tag' => self::text($t['tag'] ?? null, 100),
            'replies' => min(max(0, (int) ($t['replies'] ?? 0)), 4294967295),
            'url' => $url,
            'posted_at' => $posted->isFuture() ? Carbon::now() : $posted, // no pinning to the top
        ];
    }

    /** @param list<array<string,mixed>> $rows replaces everything stored for the partner */
    public function replace(Partner $partner, array $rows): void
    {
        $this->db->connection()->transaction(function () use ($partner, $rows) {
            FlarumverseThread::query()->where('partner_id', $partner->id)->delete();
            foreach (array_chunk($rows, 25) as $chunk) {
                FlarumverseThread::query()->insert($chunk);
            }
        });
    }

    /** @param array<string,mixed> $row */
    public function upsert(array $row): void
    {
        FlarumverseThread::query()->upsert([$row], ['partner_id', 'remote_id'], ['title', 'excerpt', 'author', 'tag', 'replies', 'url', 'posted_at']);
    }

    public static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        // Control and bidi-override characters out, whitespace collapsed.
        $value = preg_replace('/[\p{Cc}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', ' ', (string) $value) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
