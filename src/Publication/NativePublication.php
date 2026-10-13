<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Publication;

use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use RuntimeException;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\SourceTimestamp;

class NativePublication
{
    public function __construct(private readonly Configuration $configuration)
    {
    }

    public function fromRecord(array $record): ?array
    {
        $bindings = array_values(array_filter(
            is_array($record['bindings'] ?? null) ? $record['bindings'] : [],
            fn ($binding) => is_array($binding) && ($binding['role'] ?? null) === 'presentation' && ($binding['state'] ?? null) === 'active',
        ));
        if (! collect($bindings)->contains(fn ($binding) => ($binding['native_materialization'] ?? null) === true)) {
            return null;
        }
        if (count($bindings) !== 1 || ($bindings[0]['native_materialization'] ?? null) !== true) {
            throw new RuntimeException('DiscussionBridge native publication authority is ambiguous.');
        }
        if (($record['direction'] ?? null) !== 'from_discourse'
            || ! is_string($record['resource_id'] ?? null)
            || ! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $record['resource_id'])
            || ! is_int($record['topic_id'] ?? null)
            || $record['topic_id'] < 1) {
            throw new RuntimeException('DiscussionBridge native publication record is invalid.');
        }

        $destination = $this->exactUrl($bindings[0]['canonical_url'] ?? null, $this->configuration->siteOrigin(), 'destination');
        $path = trim($destination['path'], '/');
        if (! preg_match('/\Adiscussionbridge\/([a-z0-9]+(?:-[a-z0-9]+)*)\z/', $path, $match)) {
            throw new RuntimeException('DiscussionBridge native publication path is invalid.');
        }

        $source = $record['source'] ?? null;
        if (is_array($source) && array_key_exists('edition', $source) && (! is_string($source['edition']) || ! preg_match('/\A[0-9a-f]{64}\z/', $source['edition']))) {
            throw new RuntimeException('DiscussionBridge source edition is invalid.');
        }
        if (is_array($source) && ($source['status'] ?? null) === 'withdrawn') {
            if (! isset($source['edition']) || ($source['reason'] ?? null) !== 'source_unavailable' || ! array_key_exists('content_html', $record) || $record['content_html'] !== null) {
                throw new RuntimeException('DiscussionBridge source withdrawal is invalid.');
            }

            return ['resource_id' => strtolower($record['resource_id']), 'canonical_url' => $destination['url'], 'slug' => $match[1], 'topic_id' => $record['topic_id'], 'source_status' => 'withdrawn', 'source_freshness' => $source['edition']];
        }
        if (($record['state'] ?? null) !== 'healthy' || (is_array($source) && isset($source['status']) && $source['status'] !== 'active')) {
            throw new RuntimeException('DiscussionBridge source status is invalid.');
        }
        if (! is_array($source)
            || ($source['platform'] ?? null) !== 'discourse'
            || ($source['origin'] ?? null) !== $this->configuration->forumOrigin()
            || ($source['topic_id'] ?? null) !== $record['topic_id']
            || ($source['post_number'] ?? null) !== 1
            || ! is_int($source['post_id'] ?? null)
            || $source['post_id'] < 1
            || ! is_int($source['post_version'] ?? null)
            || $source['post_version'] < 1
            || ($source['revision'] ?? null) !== 'post:'.$source['post_id'].':version:'.$source['post_version']) {
            throw new RuntimeException('DiscussionBridge native publication source is invalid.');
        }
        $topicUrl = $this->sourceTopicUrl($source['topic_url'] ?? null, $record['topic_id']);
        $content = $record['content_html'] ?? null;
        $descriptor = $source['content'] ?? null;
        if ($descriptor !== null) {
            if (! is_array($descriptor) || ! is_int($descriptor['bytes'] ?? null) || $descriptor['bytes'] < 1 || $descriptor['bytes'] > 9007199254740991 || ! is_string($descriptor['sha256'] ?? null) || ! preg_match('/\A[0-9a-f]{64}\z/', $descriptor['sha256']) || ($descriptor['chunk_bytes'] ?? null) !== 32768 || ($descriptor['path'] ?? null) !== '/discussion-bridge/v1/bridge-records/'.strtolower($record['resource_id']).'/content.json' || ! isset($source['edition'])) {
                throw new RuntimeException('DiscussionBridge source content descriptor is invalid.');
            }
            if ($content !== null && (! is_string($content) || strlen($content) !== $descriptor['bytes'] || hash('sha256', $content) !== $descriptor['sha256'])) {
                throw new RuntimeException('DiscussionBridge source content identity is invalid.');
            }
        } elseif (! is_string($content) || trim($content) === '' || strlen($content) > 65536) {
            throw new RuntimeException('DiscussionBridge native publication content is invalid.');
        }
        $author = $source['author'] ?? null;
        if (! is_array($author) || ! is_string($author['name'] ?? null) || trim($author['name']) === '' || strlen($author['name']) > 200 || ! mb_check_encoding($author['name'], 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $author['name'])) {
            throw new RuntimeException('DiscussionBridge native publication author is invalid.');
        }
        $this->exactUrl($author['profile_url'] ?? null, $this->configuration->forumOrigin(), 'author');
        if (! is_string($record['title'] ?? null) || trim($record['title']) === '' || strlen($record['title']) > 1024) {
            throw new RuntimeException('DiscussionBridge native publication title is invalid.');
        }
        $createdAt = isset($source['created_at']) ? $this->isoDate($source['created_at']) : null;
        $updatedAt = isset($source['updated_at']) ? $this->isoDate($source['updated_at']) : null;
        if ($descriptor !== null && ($createdAt === null || $updatedAt === null)) {
            throw new RuntimeException('DiscussionBridge source dates are missing.');
        }
        if ($createdAt !== null && $updatedAt !== null && SourceTimestamp::precedes($updatedAt, $createdAt)) {
            throw new RuntimeException('DiscussionBridge source chronology is invalid.');
        }
        $forumName = $source['forum_name'] ?? $this->configuration->forumOrigin();
        if (! is_string($forumName) || trim($forumName) === '' || strlen($forumName) > 200 || ! mb_check_encoding($forumName, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $forumName)) {
            throw new RuntimeException('DiscussionBridge source forum name is invalid.');
        }
        $mode = $bindings[0]['presentation_mode'] ?? null;
        if ($mode !== null && ! in_array($mode, ['simple', 'full', 'interactive'], true)) {
            throw new RuntimeException('DiscussionBridge selected presentation is invalid.');
        }

        return [
            'resource_id' => strtolower($record['resource_id']),
            'canonical_url' => $destination['url'],
            'slug' => $match[1],
            'title' => trim($record['title']),
            'content_html' => $content,
            'source_revision' => $source['revision'],
            'source_freshness' => $source['edition'] ?? $source['revision'],
            'source_status' => 'active',
            'source_created_at' => $createdAt,
            'source_updated_at' => $updatedAt,
            'forum_name' => trim($forumName),
            'presentation_mode' => $mode,
            'source_author' => trim($author['name']),
            'topic_id' => $record['topic_id'],
            'topic_url' => $topicUrl,
        ];
    }

    public function isoDate(mixed $value): string
    {
        return SourceTimestamp::validate($value, 'DiscussionBridge source date is invalid.');
    }

    public function sourceTopicUrl(mixed $value, int $topicId): string
    {
        $topic = $this->exactUrl($value, $this->configuration->forumOrigin(), 'topic');
        if (! preg_match('~\A/t/(?:(?![0-9]+(?:/|$))[^/]+/)?([1-9][0-9]*)(?:/([1-9][0-9]*))?/?\z~', $topic['path'], $match)
            || (string) $topicId !== $match[1] || (isset($match[2]) && $match[2] !== '1')) {
            throw new RuntimeException('DiscussionBridge native publication topic identity is invalid.');
        }

        return $topic['url'];
    }

    private function exactUrl(mixed $value, string $origin, string $label): array
    {
        if (! is_string($value) || trim($value) !== $value || $value === '' || strlen($value) > 2048 || str_contains($value, '\\') || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new RuntimeException("DiscussionBridge {$label} URL is invalid.");
        }
        $parts = parse_url($value);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException("DiscussionBridge {$label} URL is invalid.");
        }
        $urlOrigin = 'https://'.strtolower((string) ($parts['host'] ?? '')).(isset($parts['port']) ? ':'.$parts['port'] : '');
        if ($urlOrigin !== $origin || ! is_string($parts['path'] ?? null) || ! str_starts_with($parts['path'], '/')
            || str_contains($parts['path'], '//') || preg_match('~(?:^|/)\.{1,2}(?:/|$)|%(?:25)*(?:2e|2f|5c)~i', $parts['path'])) {
            throw new RuntimeException("DiscussionBridge {$label} URL is invalid.");
        }

        return ['url' => $value, 'path' => $parts['path']];
    }
}
