<?php

namespace ErnestDefoe\Flarumverse\Listener;

use ErnestDefoe\Flarumverse\Identity;
use ErnestDefoe\Flarumverse\Job\PushThread;
use Flarum\Discussion\Event\Started;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\SyncQueue;

/**
 * New discussion → push it to partners. Only with a real queue: on Flarum's
 * default sync queue the push would run inside the request that posted, so
 * there the 15-minute pull brings it instead.
 */
class QueuePush
{
    public function __construct(
        protected Dispatcher $bus,
        protected Queue $queue,
        protected SettingsRepositoryInterface $settings,
    ) {
    }

    public function handle(Started $event): void
    {
        if ($this->queue instanceof SyncQueue || (int) $this->settings->get(Identity::PREFIX.'partners') < 1) {
            return;
        }

        $this->bus->dispatch(new PushThread((int) $event->discussion->id));
    }
}
