<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Presentation;

use CodeWorksLabs\DiscussionBridgeStatamic\Cms\CmsClient;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\ForumName;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DeliveryPresenter
{
    public function __construct(private readonly Configuration $configuration, private readonly PresentationChrome $chrome)
    {
    }

    public function render(string $entryId): string
    {
        $rows = DB::table('discussionbridge_deliveries')->where('entry_id', $entryId)->where('status', 'delivered')->limit(2)->get();
        if ($rows->count() !== 1) {
            throw new RuntimeException('DiscussionBridge delivery is unavailable or ambiguous.');
        }
        $row = $rows->first();
        app(CmsClient::class)->codec()->validate('CoreId', $row->topic_id);
        app(\CodeWorksLabs\DiscussionBridgeStatamic\Publication\NativePublication::class)
            ->sourceTopicUrl($row->topic_url, (int) $row->topic_id);
        return $this->chrome->discussion($row->topic_id, $row->topic_url, $this->configuration->forumOrigin(), false,
            ForumName::validate($row->forum_name));
    }
}