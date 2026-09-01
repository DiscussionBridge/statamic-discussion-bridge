<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Publication;

use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use RuntimeException;

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
            || ($record['state'] ?? null) !== 'healthy'
            || ! is_string($record['resource_id'] ?? null)
            || ! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $record['resource_id'])
            || ! is_int($record['topic_id'] ?? null)
            || $record['topic_id'] < 1
            || ! is_string($record['content_html'] ?? null)
            || trim($record['content_html']) === ''
            || strlen($record['content_html']) > 65536) {
            throw new RuntimeException('DiscussionBridge native publication record is invalid.');
        }

        $destination = $this->exactUrl($bindings[0]['canonical_url'] ?? null, $this->configuration->siteOrigin(), 'destination');
        $path = trim($destination['path'], '/');
        if (! preg_match('/\Adiscussionbridge\/([a-z0-9]+(?:-[a-z0-9]+)*)\z/', $path, $match)) {
            throw new RuntimeException('DiscussionBridge native publication path is invalid.');
        }

        $source = $record['source'] ?? null;
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
        $topicUrl = $this->exactUrl($source['topic_url'] ?? null, $this->configuration->forumOrigin(), 'topic')['url'];
        $author = $source['author'] ?? null;
        if (! is_array($author) || ! is_string($author['name'] ?? null) || trim($author['name']) === '' || strlen($author['name']) > 200) {
            throw new RuntimeException('DiscussionBridge native publication author is invalid.');
        }
        $this->exactUrl($author['profile_url'] ?? null, $this->configuration->forumOrigin(), 'author');
        if (! is_string($record['title'] ?? null) || trim($record['title']) === '' || strlen($record['title']) > 1024) {
            throw new RuntimeException('DiscussionBridge native publication title is invalid.');
        }

        return [
            'resource_id' => strtolower($record['resource_id']),
            'canonical_url' => $destination['url'],
            'slug' => $match[1],
            'title' => trim($record['title']),
            'content_html' => $record['content_html'],
            'source_revision' => $source['revision'],
            'source_author' => trim($author['name']),
            'topic_id' => $record['topic_id'],
            'topic_url' => $topicUrl,
        ];
    }

    private function exactUrl(mixed $value, string $origin, string $label): array
    {
        if (! is_string($value) || trim($value) !== $value || $value === '' || strlen($value) > 2048) {
            throw new RuntimeException("DiscussionBridge {$label} URL is invalid.");
        }
        $parts = parse_url($value);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])) {
            throw new RuntimeException("DiscussionBridge {$label} URL is invalid.");
        }
        $urlOrigin = 'https://'.strtolower((string) ($parts['host'] ?? '')).(isset($parts['port']) ? ':'.$parts['port'] : '');
        if ($urlOrigin !== $origin || ! is_string($parts['path'] ?? null) || ! str_starts_with($parts['path'], '/')) {
            throw new RuntimeException("DiscussionBridge {$label} URL is invalid.");
        }

        return ['url' => $value, 'path' => $parts['path']];
    }
}
