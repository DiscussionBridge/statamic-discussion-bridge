<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Presentation;

use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Statamic\Facades\Entry;

class PublicationPresenter
{
    public function __construct(private readonly Configuration $configuration, private readonly PresentationChrome $chrome)
    {
    }

    public function render(string $entryId): string
    {
        $entry = Entry::find($entryId);
        if ($entry && $entry->get('discussionbridge_source_status') === 'withdrawn') {
            return '';
        }
        $row = DB::table('discussionbridge_publications')->where('entry_id', $entryId)->first();
        if (! $row || ! is_numeric($row->topic_id) || (int) $row->topic_id < 1 || ! is_string($row->topic_url) || ! str_starts_with($row->topic_url, $this->configuration->forumOrigin().'/')) {
            throw new RuntimeException('DiscussionBridge native publication is unavailable.');
        }
        app(\CodeWorksLabs\DiscussionBridgeStatamic\Publication\NativePublication::class)->sourceTopicUrl($row->topic_url, (int) $row->topic_id);

        $mode = $entry ? $entry->get('discussionbridge_mode') : null;
        $standalone = app(StandalonePresenter::class);
        return match ($mode) {
            'simple' => $standalone->simple((int) $row->topic_id),
            'full' => $standalone->fullTopic((int) $row->topic_id),
            null, 'interactive' => $this->chrome->discussion((int) $row->topic_id, $row->topic_url, $this->configuration->forumOrigin(), true),
            default => throw new RuntimeException('Statamic publication presentation is invalid.'),
        };
    }
}
