<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Listeners;

use CodeWorksLabs\DiscussionBridgeStatamic\Cms\NativeWriteGuard;
use Statamic\Events\EntrySaved;

class ReleaseCmsNativeSave
{
    public function handle(EntrySaved $event): void
    {
        app(NativeWriteGuard::class)->saved($event->entry);
    }
}
