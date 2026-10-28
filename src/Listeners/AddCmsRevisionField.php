<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Listeners;

use Statamic\Events\EntryBlueprintFound;

class AddCmsRevisionField
{
    public function handle(EntryBlueprintFound $event): void
    {
        if ($event->entry?->get('discussionbridge_cms_owned') !== true) {
            return;
        }
        // Native publish-form values carry the version loaded before editing.
        // The CP's normal fields process/merge then reaches the guarded repository.
        $event->blueprint->ensureField('discussionbridge_cms_native_version', [
            'type' => 'text', 'visibility' => 'hidden', 'validate' => ['required', 'uuid'],
        ], 'sidebar');
    }
}
