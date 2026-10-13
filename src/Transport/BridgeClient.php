<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Transport;

use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

class BridgeClient
{
    public const PUBLICATION_CONTRACT = '0.3.0-alpha.1';
    /** @var array<string, array{expires: int, enabled: bool}> */
    private static array $brandingCache = [];

    public function __construct(
        private readonly Client $http,
        private readonly Configuration $configuration,
    ) {
    }

    public function resolve(array $record): array
    {
        $json = json_encode(['bridge_record' => $record], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($json) > 65536) {
            throw new RuntimeException('DiscussionBridge request is too large.');
        }

        return $this->request('POST', '/discussion-bridge/v1/bridge-records/resolve.json', $json);
    }

    public function record(string $resourceId): array
    {
        if (! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $resourceId)) {
            throw new RuntimeException('DiscussionBridge resource ID is invalid.');
        }

        return $this->request('GET', '/discussion-bridge/v1/bridge-records/'.rawurlencode(strtolower($resourceId)).'.json?publication_contract='.self::PUBLICATION_CONTRACT, maximum: 2097152);
    }

    public function records(int $page = 1, ?string $snapshot = null): array
    {
        if ($page < 1 || $page > 10000) {
            throw new RuntimeException('DiscussionBridge records page is invalid.');
        }

        if ($snapshot !== null && ($snapshot === '' || strlen($snapshot) > 8192)) {
            throw new RuntimeException('DiscussionBridge records snapshot is invalid.');
        }
        $query = ['page' => $page, 'publication_contract' => self::PUBLICATION_CONTRACT];
        if ($snapshot !== null) {
            $query['snapshot'] = $snapshot;
        }

        return $this->request('GET', '/discussion-bridge/v1/bridge-records.json?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986), maximum: 2097152);
    }

    public function publicationContent(array $record): string
    {
        $resource = strtolower((string) ($record['resource_id'] ?? ''));
        $source = $record['source'] ?? null;
        $descriptor = is_array($source) ? ($source['content'] ?? null) : null;
        if ($descriptor === null && is_string($record['content_html'] ?? null)) {
            return $record['content_html'];
        }
        if (! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $resource) || ! is_array($source) || ! is_string($source['edition'] ?? null) || ! preg_match('/\A[0-9a-f]{64}\z/', $source['edition']) || ! is_array($descriptor)
            || ! is_int($descriptor['bytes'] ?? null) || $descriptor['bytes'] < 1 || $descriptor['bytes'] > 9007199254740991
            || ! is_string($descriptor['sha256'] ?? null) || ! preg_match('/\A[0-9a-f]{64}\z/', $descriptor['sha256'])
            || ($descriptor['chunk_bytes'] ?? null) !== 32768 || ($descriptor['path'] ?? null) !== '/discussion-bridge/v1/bridge-records/'.$resource.'/content.json') {
            throw new RuntimeException('DiscussionBridge source content descriptor is invalid.');
        }
        $content = '';
        $digest = hash_init('sha256');
        for ($offset = 0; $offset < $descriptor['bytes'];) {
            $query = http_build_query(['edition' => $source['edition'], 'offset' => $offset], '', '&', PHP_QUERY_RFC3986);
            $chunk = $this->request('GET', $descriptor['path'].'?'.$query, maximum: 65536);
            if (($chunk['resource_id'] ?? null) !== $resource || ($chunk['edition'] ?? null) !== $source['edition'] || ($chunk['offset'] ?? null) !== $offset || ($chunk['total_bytes'] ?? null) !== $descriptor['bytes'] || ($chunk['content_sha256'] ?? null) !== $descriptor['sha256'] || ! is_string($chunk['chunk_base64'] ?? null)) {
                throw new RuntimeException('DiscussionBridge source content chunk identity is invalid.');
            }
            $bytes = base64_decode($chunk['chunk_base64'], true);
            if (! is_string($bytes) || base64_encode($bytes) !== $chunk['chunk_base64'] || strlen($bytes) !== min(32768, $descriptor['bytes'] - $offset)) {
                throw new RuntimeException('DiscussionBridge source content chunk length is invalid.');
            }
            $offset += strlen($bytes);
            if (! array_key_exists('next_offset', $chunk) || $chunk['next_offset'] !== ($offset === $descriptor['bytes'] ? null : $offset)) {
                throw new RuntimeException('DiscussionBridge source content continuation is invalid.');
            }
            hash_update($digest, $bytes);
            $content .= $bytes;
        }
        if (hash_final($digest) !== $descriptor['sha256'] || ! mb_check_encoding($content, 'UTF-8')) {
            throw new RuntimeException('DiscussionBridge source complete content identity is invalid.');
        }

        return $content;
    }

    public function publicTopic(int $topicId): array
    {
        if ($topicId < 1) {
            throw new RuntimeException('Discourse topic ID is invalid.');
        }

        return $this->request('GET', '/t/'.$topicId.'.json', null, false);
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

        return $this->request('GET', '/t/'.$topicId.'/posts.json?'.$query, null, false);
    }

    public function publicPoweredByDiscourse(): bool
    {
        $origin = $this->configuration->forumOrigin();
        $cached = self::$brandingCache[$origin] ?? null;
        if (is_array($cached) && $cached['expires'] > time()) {
            return $cached['enabled'];
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
        $enabled = $settings['enable_powered_by_discourse'] ?? null;
        if (! is_bool($enabled)) {
            throw new RuntimeException('Discourse branding setting is invalid.');
        }
        self::$brandingCache[$origin] = ['expires' => time() + 600, 'enabled' => $enabled];

        return $enabled;
    }

    private function request(string $method, string $path, ?string $json = null, bool $authenticate = true, ?int $maximum = null): array
    {
        $headers = ['Accept' => 'application/json'];
        if ($authenticate) {
            $headers['X-DiscussionBridge-Connection'] = $this->configuration->connectionId();
            $headers['X-DiscussionBridge-Secret'] = $this->configuration->secret();
            $headers['X-DiscussionBridge-Adapter'] = (string) config('discussionbridge.adapter_id');
            $headers['X-DiscussionBridge-Adapter-Version'] = (string) config('discussionbridge.adapter_version');
        }
        if ($json !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        try {
            $response = $this->http->request($method, $this->configuration->forumOrigin().$path, [
                'allow_redirects' => false,
                'connect_timeout' => (float) config('discussionbridge.connect_timeout_seconds', 2),
                'timeout' => (float) config('discussionbridge.response_timeout_seconds', 5),
                'read_timeout' => (float) config('discussionbridge.response_timeout_seconds', 5),
                'http_errors' => false,
                'stream' => true,
                'headers' => $headers,
                ...($json === null ? [] : ['body' => $json]),
            ]);
        } catch (Throwable) {
            throw new RuntimeException('DiscussionBridge transport failed.');
        }

        $data = $this->decode($response, $maximum);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $reason = is_string($data['reason'] ?? null) ? substr($data['reason'], 0, 100) : 'request_failed';
            throw new BridgeRequestException($status, $reason);
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
