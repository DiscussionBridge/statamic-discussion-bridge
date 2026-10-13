<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Presentation;

use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use CodeWorksLabs\DiscussionBridgeStatamic\Transport\BridgeClient;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use CodeWorksLabs\DiscussionBridgeStatamic\Publication\NativePublication;
use CodeWorksLabs\DiscussionBridgeStatamic\Transport\BridgeRequestException;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\PresentationAssets;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\SourceTimestamp;

class RecordPresenter
{
    public function __construct(
        private readonly BridgeClient $client,
        private readonly Configuration $configuration,
        private readonly HtmlSanitizer $sanitizer,
        private readonly PageNavigation $navigation,
        private readonly PresentationChrome $chrome,
    ) {
    }

    public function render(string $resourceId): string
    {
        // Check current native source eligibility before reusing any cached body.
        $record = $this->validatedRecord($resourceId);

        $content = $this->sanitizer->sanitize($record['content_html'], isset($record['source']['content']) ? null : 65536);
        if ($content === '') {
            throw new RuntimeException('DiscussionBridge content is empty after sanitization.');
        }
        return '<section class="discussionbridge-record">'
            .'<article class="discussionbridge-article">'.$this->navigation->render($content).$this->sourceFooter($record).'</article>'
            .$this->chrome->discussion($record['topic_id'], $record['topic_url'], $this->configuration->forumOrigin(), true)
            .'</section>'.PresentationAssets::markup();
    }

    private function sourceFooter(array $record): string
    {
        $source = $record['source'] ?? [];
        $name = $source['forum_name'] ?? $this->configuration->forumOrigin();
        if (! is_string($name) || trim($name) === '' || strlen($name) > 200 || ! mb_check_encoding($name, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new RuntimeException('DiscussionBridge source forum name is invalid.');
        }
        $validator = app(NativePublication::class);
        $created = isset($source['created_at']) ? $validator->isoDate($source['created_at']) : null;
        $updated = isset($source['updated_at']) ? $validator->isoDate($source['updated_at']) : null;
        if ($created !== null && $updated !== null && SourceTimestamp::precedes($updated, $created)) {
            throw new RuntimeException('DiscussionBridge source chronology is invalid.');
        }
        $escape = fn (string $text) => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<aside class="discussionbridge-source"><p>Source: <a href="'.$escape($record['topic_url']).'">'.$escape($name).'</a>'
            .($updated === null ? '' : ' · Updated <time datetime="'.$escape($updated).'">'.$escape($updated).'</time>')
            .'</p></aside>';
    }

    private function validatedRecord(string $resourceId, int $attempt = 0): array
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
            || ($record['source']['status'] ?? 'active') !== 'active'
            || (! isset($record['source']['content']) && (! is_string($record['content_html'] ?? null) || strlen($record['content_html']) > 65536))) {
            throw new RuntimeException('DiscussionBridge record response is invalid.');
        }
        app(NativePublication::class)->sourceTopicUrl($record['topic_url'], $record['topic_id']);
        $key = 'discussionbridge:record:'.hash('sha256', $this->configuration->siteOrigin().':'.strtolower($resourceId));
        if (isset($record['source']['content'])) {
            if (! is_string($record['source']['edition'] ?? null) || ! preg_match('/\A[0-9a-f]{64}\z/', $record['source']['edition'])) {
                throw new RuntimeException('DiscussionBridge source edition is invalid.');
            }
            $cached = Cache::get($key);
            if (is_array($cached) && ($cached['source']['edition'] ?? null) === ($record['source']['edition'] ?? null) && is_string($cached['content_html'] ?? null) && strlen($cached['content_html']) === ($record['source']['content']['bytes'] ?? null) && hash('sha256', $cached['content_html']) === ($record['source']['content']['sha256'] ?? null)) {
                $record['content_html'] = $cached['content_html'];
            } else {
                try {
                    $record['content_html'] = $this->client->publicationContent($record);
                } catch (BridgeRequestException $error) {
                    if ($error->status !== 409 || $attempt >= 2) {
                        throw $error;
                    }
                    return $this->validatedRecord($resourceId, $attempt + 1);
                }
                Cache::put($key, $record, max(1, min((int) config('discussionbridge.presentation_cache_seconds', 60), 3600)));
            }
        }

        return $record;
    }
}
