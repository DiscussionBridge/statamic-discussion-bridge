<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Publication;

use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use RuntimeException;

/** Existing native Discourse topic URL/identity validation; no old record grammar. */
class NativePublication
{
    public function __construct(private readonly Configuration $configuration)
    {
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
