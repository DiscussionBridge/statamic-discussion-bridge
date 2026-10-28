<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Listeners;

use CodeWorksLabs\DiscussionBridgeStatamic\Cms\ToClosures;
use Statamic\Events\EntryDeleting;

class GuardToNativeOperation
{
    public function handle($event): void
    {
        app(ToClosures::class)->guardEvent($event->entry, $event instanceof EntryDeleting ? 'delete' : 'save');
    }
}
