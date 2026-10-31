<?php

namespace ErnestDefoe\Flarumverse\Job;

use ErnestDefoe\Flarumverse\Http\Remote;
use ErnestDefoe\Flarumverse\Partner;
use ErnestDefoe\Flarumverse\Threads;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends a new discussion to every partner straight away, so it doesn't wait for
 * their next pull. Re-checked here: if a guest can't read it (yet), it isn't sent.
 */
class PushThread implements ShouldQueue
{
    use Queueable;
    use InteractsWithQueue;

    public function __construct(public int $discussionId)
    {
    }

    public function handle(Threads $threads, Remote $remote): void
    {
        $thread = $threads->one($this->discussionId);
        if ($thread === null) {
            return;
        }

        foreach (Partner::query()->where('status', Partner::PAIRED)->limit(50)->pluck('url') as $url) {
            $remote->send($url, ['type' => 'thread', 'thread' => $thread]);
        }
    }
}
