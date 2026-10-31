<?php

namespace ErnestDefoe\Flarumverse;

use Carbon\Carbon;
use ErnestDefoe\Flarumverse\Http\Remote;
use Flarum\Foundation\ValidationException;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Asking, approving, declining and ending pairings, from both ends.
 *
 * Statuses: outgoing (we asked), incoming (they asked), paired, declined (we
 * said no; they can't ask again for 30 days), refused (they said no).
 */
class Pairing
{
    public const MAX_PARTNERS = 50;
    public const RETRY_AFTER_DECLINE_DAYS = 30;

    public function __construct(
        protected Remote $remote,
        protected Identity $identity,
        protected Threads $threads,
        protected SettingsRepositoryInterface $settings,
    ) {
    }

    // ---- Our admin's actions ------------------------------------------------

    public function request(string $input): Partner
    {
        $url = Address::normalise($input);
        if ($url === null) {
            throw new ValidationException(['url' => $this->trans('invalid_address')]);
        }
        if ($url === $this->identity->url()) {
            throw new ValidationException(['url' => $this->trans('own_address')]);
        }

        $partner = Partner::forUrl($url);
        if ($partner?->status === Partner::INCOMING) {
            return $this->approve($partner); // they already asked: that's a yes
        }
        if ($partner?->status === Partner::PAIRED) {
            return $partner;
        }
        $this->assertRoom();

        $about = $this->remote->about($url);
        if ($about === null) {
            throw new ValidationException(['url' => $this->trans('not_a_flarumverse_forum')]);
        }

        $partner ??= new Partner();
        $partner->url = $url;
        $partner->name = $about['name'];
        $partner->description = $about['description'] ?: null;
        $partner->public_key = $about['publicKey'];
        $partner->applyLook($about);
        $partner->status = Partner::OUTGOING;
        $partner->save();

        [$status, $reply] = $this->remote->send($url, ['type' => 'pair-request']);
        if ($status < 200 || $status >= 300) {
            $partner->delete();
            throw new ValidationException(['url' => $this->trans(match ($reply['error'] ?? null) {
                'declined' => 'declined_recently',
                'full' => 'they_are_full',
                'busy' => 'they_are_busy',
                default => 'request_failed',
            })]);
        }
        if (($reply['status'] ?? null) === Partner::PAIRED) {
            $this->markPaired($partner); // they had asked us too
        }

        return $partner;
    }

    public function approve(Partner $partner): Partner
    {
        if ($partner->status !== Partner::INCOMING) {
            return $partner;
        }
        $this->assertRoom();
        $this->markPaired($partner);
        $this->remote->send($partner->url, ['type' => 'pair-accept']);
        $this->pull($partner);

        return $partner;
    }

    public function decline(Partner $partner): void
    {
        if ($partner->status !== Partner::INCOMING) {
            return;
        }
        $partner->status = Partner::DECLINED;
        $partner->save(); // updated_at = when, for the 30-day wait
        $this->remote->send($partner->url, ['type' => 'pair-decline']);
    }

    public function remove(Partner $partner): void
    {
        if (in_array($partner->status, [Partner::PAIRED, Partner::OUTGOING], true)) {
            $this->remote->send($partner->url, ['type' => 'unpair']);
        }
        $partner->delete();
        $this->recount();
    }

    // ---- Messages from other forums -----------------------------------------

    /**
     * A signed pair-request from a forum we may not know yet.
     *
     * @param array{url:string,name:string,description:string,publicKey:string} $about
     * @return array<string,string> the reply
     */
    public function received(array $about, ?Partner $partner): array
    {
        if ($partner?->status === Partner::PAIRED) {
            return ['status' => Partner::PAIRED];
        }
        if ($partner?->status === Partner::OUTGOING) {
            $this->markPaired($partner); // we asked them too

            return ['status' => Partner::PAIRED];
        }
        if ($partner?->status === Partner::DECLINED
            && $partner->updated_at->gt(Carbon::now()->subDays(self::RETRY_AFTER_DECLINE_DAYS))) {
            return ['error' => 'declined'];
        }
        if (Partner::query()->where('status', Partner::PAIRED)->count() >= self::MAX_PARTNERS) {
            return ['error' => 'full'];
        }

        $partner ??= new Partner();
        $partner->url = $about['url'];
        $partner->name = $about['name'];
        $partner->description = $about['description'] ?: null;
        $partner->public_key = $about['publicKey'];
        $partner->applyLook($about);
        $partner->status = Partner::INCOMING;
        $partner->save();

        return ['status' => Partner::INCOMING];
    }

    public function accepted(Partner $partner): void
    {
        if ($partner->status === Partner::OUTGOING) {
            $this->markPaired($partner);
            $this->pull($partner);
        }
    }

    public function refused(Partner $partner): void
    {
        if ($partner->status === Partner::OUTGOING) {
            $partner->status = Partner::REFUSED;
            $partner->save();
        }
    }

    public function ended(Partner $partner): void
    {
        if ($partner->status === Partner::DECLINED) {
            return; // keep our "no" and its 30-day wait
        }
        $partner->delete();
        $this->recount();
    }

    // ---- Threads ------------------------------------------------------------

    /** Fetch a partner's latest threads and replace what we have. */
    public function pull(Partner $partner): bool
    {
        [$status, $reply] = $this->remote->threads($partner->url);
        $partner->last_attempt_at = Carbon::now();

        if ($status !== 200 || ! is_array($reply['threads'] ?? null)) {
            $partner->failures = min($partner->failures + 1, 1000);
            $partner->last_error = $status === 0 ? 'unreachable' : 'http '.$status;
            $partner->save();

            return false;
        }

        $rows = [];
        foreach (array_slice($reply['threads'], 0, Threads::LIMIT) as $t) {
            if ($row = Threads::clean($t, $partner)) {
                $rows[$row['remote_id']] = $row;
            }
        }
        $this->threads->replace($partner, array_values($rows));

        $partner->failures = 0;
        $partner->last_error = null;
        $partner->save();

        return true;
    }

    private function markPaired(Partner $partner): void
    {
        $partner->status = Partner::PAIRED;
        $partner->paired_at = Carbon::now();
        $partner->save();
        $this->recount();
    }

    /** The forum payload only carries a number, kept here so pages cost no query. */
    private function recount(): void
    {
        $this->settings->set(Identity::PREFIX.'partners', (string) Partner::query()->where('status', Partner::PAIRED)->count());
    }

    private function assertRoom(): void
    {
        if (Partner::query()->where('status', Partner::PAIRED)->count() >= self::MAX_PARTNERS) {
            throw new ValidationException(['url' => $this->trans('we_are_full', ['max' => self::MAX_PARTNERS])]);
        }
    }

    /** @param array<string,mixed> $params */
    private function trans(string $key, array $params = []): string
    {
        return resolve('translator')->trans('ernestdefoe-flarumverse.api.'.$key, $params);
    }
}
