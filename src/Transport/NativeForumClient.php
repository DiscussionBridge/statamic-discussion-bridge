<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Transport;

use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\ForumName;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

class NativeForumClient
{
    /** @var array<string, array{expires: int, settings: array}> */
    private static array $brandingCache = [];

    public function __construct(private readonly Client $http, private readonly Configuration $configuration)
    {
    }

    public function publicTopic(int $topicId): array
    {
        if ($topicId < 1) {
            throw new RuntimeException('Discourse topic ID is invalid.');
        }

        return $this->request('GET', '/t/'.$topicId.'.json');
    }

    /** @param list<int> $postIds */
    public function publicTopicPosts(int $topicId, array $postIds): array
    {
        if ($topicId < 1 || $postIds === [] || count($postIds) > 20) {
            throw new RuntimeException('Discourse topic post request is invalid.');
        }
        foreach ($postIds as $postId) {
            if (! is_int($postId) || $postId < 1) {
                throw new RuntimeException('Discourse topic post request is invalid.');
            }
        }
        $query = http_build_query(['post_ids' => array_values(array_unique($postIds))]);

        return $this->request('GET', '/t/'.$topicId.'/posts.json?'.$query);
    }

    public function publicPoweredByDiscourse(): bool
    {
        $enabled = $this->publicSettings()['enable_powered_by_discourse'] ?? null;
        if (! is_bool($enabled)) {
            throw new RuntimeException('Discourse branding setting is invalid.');
        }

        return $enabled;
    }

    public function publicForumTitle(): string
    {
        try {
            return ForumName::validate($this->publicSettings()['title'] ?? null);
        } catch (Throwable) {
            throw new RuntimeException('DiscussionBridge forum_configuration_invalid: native forum title is unavailable or invalid.');
        }
    }

    public function forumName(): string
    {
        return $this->configuration->forumNameOverride() ?? $this->publicForumTitle();
    }

    private function publicSettings(): array
    {
        $origin = $this->configuration->forumOrigin();
        $cached = self::$brandingCache[$origin] ?? null;
        if (is_array($cached) && $cached['expires'] > time()) {
            return $cached['settings'];
        }
        try {
            $response = $this->http->request('GET', $origin.'/', [
                'allow_redirects' => false,
                'connect_timeout' => (float) config('discussionbridge.connect_timeout_seconds', 2),
                'timeout' => (float) config('discussionbridge.response_timeout_seconds', 5),
                'read_timeout' => (float) config('discussionbridge.response_timeout_seconds', 5),
                'http_errors' => false,
                'stream' => true,
                'headers' => [
                    'Accept' => 'text/html',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
                ],
            ]);
        } catch (Throwable) {
            throw new RuntimeException('Discourse branding transport failed.');
        }
        if ($response->getStatusCode() !== 200 || ! str_starts_with(strtolower($response->getHeaderLine('Content-Type')), 'text/html')) {
            throw new RuntimeException('Discourse branding response is invalid.');
        }
        $body = $response->getBody();
        $html = '';
        while (! $body->eof()) {
            $html .= $body->read(min(8192, 512 * 1024 + 1 - strlen($html)));
            if (strlen($html) > 512 * 1024) {
                throw new RuntimeException('Discourse branding response is too large.');
            }
        }
        if (preg_match('/<script[^>]+id=["\']data-preloaded["\'][^>]*>(.*?)<\/script>/is', $html, $match) !== 1) {
            throw new RuntimeException('Discourse branding setting is unavailable.');
        }
        try {
            $outer = json_decode($match[1], true, 64, JSON_THROW_ON_ERROR);
            $settings = json_decode($outer['siteSettings'] ?? '', true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('Discourse branding setting is invalid.');
        }
        if (! is_array($settings)) {
            throw new RuntimeException('Discourse bootstrap settings are invalid.');
        }
        self::$brandingCache[$origin] = ['expires' => time() + 600, 'settings' => $settings];

        return $settings;
    }

    /** Native public Discourse integration; never sends Bridge credentials. */
    private function request(string $method, string $path): array
    {
        if ($method !== 'GET') {
            throw new RuntimeException('Discourse public request is invalid.');
        }
        try {
            $response = $this->http->request('GET', $this->configuration->forumOrigin().$path, [
                'allow_redirects' => false,
                'connect_timeout' => (float) config('discussionbridge.connect_timeout_seconds', 2),
                'timeout' => (float) config('discussionbridge.response_timeout_seconds', 5),
                'read_timeout' => (float) config('discussionbridge.response_timeout_seconds', 5),
                'http_errors' => false, 'stream' => true,
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (Throwable) {
            throw new RuntimeException('Discourse public transport failed.');
        }
        $data = $this->decode($response);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Discourse public request failed.');
        }
        return $data;
    }

    private function decode(ResponseInterface $response, ?int $maximum = null): array
    {
        $contentType = strtolower($response->getHeaderLine('Content-Type'));
        if (! str_starts_with($contentType, 'application/json')) {
            $response->getBody()->close();
            throw new RuntimeException('DiscussionBridge response content type is invalid.');
        }

        $maximum ??= (int) config('discussionbridge.maximum_response_bytes', 65536);
        $declared = $response->getHeaderLine('Content-Length');
        if ($declared !== '' && (! ctype_digit($declared) || (int) $declared > $maximum)) {
            $response->getBody()->close();
            throw new RuntimeException('DiscussionBridge response is too large.');
        }

        $body = $response->getBody();
        $bytes = '';
        try {
            while (! $body->eof()) {
                $chunk = $body->read(min(8192, $maximum + 1 - strlen($bytes)));
                if ($chunk === '' && ! $body->eof()) {
                    throw new RuntimeException('DiscussionBridge response stalled.');
                }
                $bytes .= $chunk;
                if (strlen($bytes) > $maximum) {
                    throw new RuntimeException('DiscussionBridge response is too large.');
                }
            }
        } finally {
            $body->close();
        }

        try {
            $decoded = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('DiscussionBridge response JSON is invalid.');
        }
        if (! is_array($decoded)) {
            throw new RuntimeException('DiscussionBridge response JSON is invalid.');
        }

        return $decoded;
    }
}
