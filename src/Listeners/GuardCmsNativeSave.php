<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Listeners;

use CodeWorksLabs\DiscussionBridgeStatamic\Cms\NativeWriteGuard;
use Statamic\Events\EntrySaving;

class GuardCmsNativeSave
{
    public function handle(EntrySaving $event): void
    {
        app(NativeWriteGuard::class)->saving($event->entry);
    }
}
