<?php

namespace ErnestDefoe\Flarumverse\Console;

use Carbon\Carbon;
use ErnestDefoe\Flarumverse\Pairing;
use ErnestDefoe\Flarumverse\Partner;
use Illuminate\Console\Command;

/**
 * flarumverse:pull — refresh every partner's latest threads (scheduled every 15
 * minutes). Edits, deletions and discussions made private on a partner drop
 * out here. A partner that keeps failing is tried less often, up to once a day.
 */
class PullCommand extends Command
{
    protected $signature = 'flarumverse:pull';

    protected $description = 'Fetch the latest shared discussions from every paired forum.';

    public function handle(Pairing $pairing, \ErnestDefoe\Flarumverse\Http\Remote $remote): int
    {
        $partners = Partner::query()->where('status', Partner::PAIRED)->orderBy('last_attempt_at')->get();

        foreach ($partners as $partner) {
            $wait = min(15 * 2 ** min($partner->failures, 7), 1440);
            if ($partner->failures > 0 && $partner->last_attempt_at?->gt(Carbon::now()->subMinutes($wait))) {
                continue;
            }
            $ok = $pairing->pull($partner);

            // Its favicon, logo and colour, once a day (one small request).
            if (($partner->look_checked_at === null || $partner->look_checked_at->lt(Carbon::now()->subDay()))) {
                $about = $remote->about($partner->url);
                if ($about) {
                    $partner->applyLook($about);
                } else {
                    $partner->look_checked_at = Carbon::now();
                }
                $partner->save();
            }
            $this->line(($ok ? 'ok   ' : 'fail ').$partner->url);
        }

        return 0;
    }
}
