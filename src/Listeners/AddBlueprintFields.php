<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Listeners;

use Statamic\Events\EntryBlueprintFound;

class AddBlueprintFields
{
    public function handle(EntryBlueprintFound $event): void
    {
        if (! $event->entry || ! in_array($event->entry->collectionHandle(), config('discussionbridge.collections', []), true)) {
            return;
        }

        $event->blueprint->ensureField('discussionbridge_publish', [
            'type' => 'toggle',
            'display' => 'Publish to DiscussionBridge',
            'instructions' => 'Create one stable Bridge Record when this entry first becomes eligible and published.',
            'default' => false,
            'width' => 50,
        ], 'sidebar');

        $event->blueprint->ensureField('discussionbridge_resource_id', [
            'type' => 'text',
            'display' => 'DiscussionBridge source resource',
            'instructions' => 'Optional From Discourse Bridge Record UUID rendered by the DiscussionBridge tag.',
            'validate' => ['nullable', 'uuid'],
            'width' => 50,
        ], 'sidebar');
    }
}
