<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Presentation;

use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DeliveryPresenter
{
    public function __construct(
        private readonly Configuration $configuration,
        private readonly PresentationChrome $chrome,
    ) {
    }

    public function render(string $entryId): string
    {
        $row = DB::table('discussionbridge_deliveries')
            ->where('entry_id', $entryId)
            ->where('status', 'delivered')
            ->first();
        if (! $row
            || ! is_numeric($row->topic_id)
            || (int) $row->topic_id < 1
            || ! is_string($row->topic_url)
            || ! str_starts_with($row->topic_url, $this->configuration->forumOrigin().'/')) {
            throw new RuntimeException('DiscussionBridge delivery is unavailable.');
        }

        return $this->chrome->discussion((int) $row->topic_id, $row->topic_url, $this->configuration->forumOrigin(), false);
    }
}
