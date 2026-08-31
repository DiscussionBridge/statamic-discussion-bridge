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

        $event->blueprint->ensureField('discussionbridge_mode', [
            'type' => 'select',
            'display' => 'DiscussionBridge presentation mode',
            'instructions' => 'Optional plugin-free Simple or Full comments presentation for this entry.',
            'options' => ['simple' => 'Simple', 'full' => 'Full'],
            'clearable' => true,
            'width' => 50,
        ], 'sidebar');

        $event->blueprint->ensureField('discussionbridge_topic_id', [
            'type' => 'integer',
            'display' => 'Discourse topic ID',
            'instructions' => 'Required only for Simple mode. Full mode resolves by this entry’s canonical URL.',
            'validate' => ['nullable', 'integer', 'min:1'],
            'width' => 50,
        ], 'sidebar');
    }
}
