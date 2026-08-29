<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Presentation;

use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use CodeWorksLabs\DiscussionBridgeStatamic\Transport\BridgeClient;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class RecordPresenter
{
    public function __construct(
        private readonly BridgeClient $client,
        private readonly Configuration $configuration,
        private readonly HtmlSanitizer $sanitizer,
    ) {
    }

    public function render(string $resourceId): string
    {
        $record = Cache::remember(
            'discussionbridge:record:'.hash('sha256', $this->configuration->siteOrigin().':'.strtolower($resourceId)),
            max(1, min((int) config('discussionbridge.presentation_cache_seconds', 60), 3600)),
            fn () => $this->validatedRecord($resourceId),
        );

        $content = $this->sanitizer->sanitize($record['content_html']);
        if ($content === '') {
            throw new RuntimeException('DiscussionBridge content is empty after sanitization.');
        }
        $topic = htmlspecialchars($record['topic_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<section class="discussionbridge-record">'.$content.'<p class="discussionbridge-credit"><a href="'.$topic.'" rel="nofollow noopener noreferrer">Continue the discussion in Discourse</a></p></section>';
    }

    private function validatedRecord(string $resourceId): array
    {
        $response = $this->client->record($resourceId);
        $record = $response['bridge_record'] ?? null;
        if (! is_array($record)
            || strtolower((string) ($record['resource_id'] ?? '')) !== strtolower($resourceId)
            || ($record['direction'] ?? null) !== 'from_discourse'
            || ($record['state'] ?? null) !== 'healthy'
            || ! is_int($record['topic_id'] ?? null)
            || $record['topic_id'] < 1
            || ! is_string($record['topic_url'] ?? null)
            || ! str_starts_with($record['topic_url'], $this->configuration->forumOrigin().'/')
            || ! is_string($record['content_html'] ?? null)
            || strlen($record['content_html']) > 65536) {
            throw new RuntimeException('DiscussionBridge record response is invalid.');
        }

        return $record;
    }
}
