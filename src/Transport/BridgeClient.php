<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Transport;

use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

class BridgeClient
{
    /** @var array<string, array{expires: int, enabled: bool}> */
    private static array $brandingCache = [];

    private ?string $publicationLeaseToken = null;

    public function __construct(
        private readonly Client $http,
        private readonly Configuration $configuration,
    ) {
    }

    public function resolve(array $record): array
    {
        $json = json_encode(['bridge_record' => $record], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
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

        return $this->request('GET', '/discussion-bridge/v1/bridge-records/'.rawurlencode(strtolower($resourceId)).'.json');
    }

    public function records(int $page = 1, ?string $snapshot = null): array
    {
        if ($page < 1 || $page > 10000) {
            throw new RuntimeException('DiscussionBridge records page is invalid.');
        }

        if ($snapshot !== null && ($snapshot === '' || strlen($snapshot) > 8192)) {
            throw new RuntimeException('DiscussionBridge records snapshot is invalid.');
        }
        $query = ['page' => $page];
        if ($snapshot !== null) {
            $query['snapshot'] = $snapshot;
        }

        return $this->request(
            'GET',
            '/discussion-bridge/v1/bridge-records.json?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986),
            null,
            true,
            1024 * 1024,
        );
    }

    public function platformCatalogStatus(): array
    {
        return $this->request('GET', '/discussion-bridge/v1/platform-catalog.json', null, true, 1024 * 1024);
    }

    public function updatePlatformCatalog(array $catalog, ?string $expectedRevision = null): array
    {
        $payload = ['catalog' => $catalog];
        if ($expectedRevision !== null) {
            if (! preg_match('/\A[a-f0-9]{64}\z/', $expectedRevision)) {
                throw new RuntimeException('DiscussionBridge expected catalog revision is invalid.');
            }
            $payload['expected_catalog_revision'] = $expectedRevision;
        }
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > 262144) {
            throw new RuntimeException('DiscussionBridge platform catalog is too large.');
        }

        return $this->request('PUT', '/discussion-bridge/v1/platform-catalog.json', $json, true, 1024 * 1024);
    }

    public function sourceTopic(int $topicId): array
    {
        if ($topicId < 1) {
            throw new RuntimeException('DiscussionBridge source topic ID is invalid.');
        }

        return $this->request('GET', '/discussion-bridge/v1/source-topics/'.$topicId.'.json');
    }

    public function sourceRevocation(string $resourceId): array
    {
        $this->assertResourceId($resourceId);

        return $this->request('GET', '/discussion-bridge/v1/source-revocations/'.rawurlencode(strtolower($resourceId)).'.json');
    }

    public function claimPublicationWork(int $leaseSeconds = 300): array
    {
        $this->publicationLeaseToken = null;
        if ($leaseSeconds < 300 || $leaseSeconds > 3600) {
            throw new RuntimeException('DiscussionBridge publication lease duration is invalid.');
        }
        $json = json_encode(['lease_seconds' => $leaseSeconds], JSON_THROW_ON_ERROR);
        $response = $this->request('POST', '/discussion-bridge/v1/publication-work/claim.json', $json);
        $work = $response['publication_work'] ?? null;
        if ($work === null) {
            return $response;
        }
        if (! is_array($work)
            || ! is_int($work['topic_id'] ?? null)
            || $work['topic_id'] < 1
            || ! in_array($work['action'] ?? null, ['publish', 'unpublish'], true)
            || ! is_string($work['lease_token'] ?? null)
            || ! preg_match('/\A[a-f0-9]{64}\z/', $work['lease_token'])) {
            throw new RuntimeException('DiscussionBridge publication work claim is invalid.');
        }
        $this->publicationLeaseToken = $work['lease_token'];

        return $response;
    }

    public function resolveSourceTopic(int $topicId, array $publication): array
    {
        if ($topicId < 1) {
            throw new RuntimeException('DiscussionBridge source topic ID is invalid.');
        }
        $json = json_encode(['publication' => $publication], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $this->request('POST', '/discussion-bridge/v1/source-topics/'.$topicId.'/resolve.json', $json);
    }

    public function acknowledgePublication(string $resourceId, array $acknowledgement): array
    {
        $this->assertResourceId($resourceId);
        if ($this->publicationLeaseToken !== null) {
            $acknowledgement['lease_token'] = $this->publicationLeaseToken;
        }
        $json = json_encode(['acknowledgement' => $acknowledgement], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $this->request('PUT', '/discussion-bridge/v1/bridge-records/'.rawurlencode(strtolower($resourceId)).'/acknowledgement.json', $json);
    }

    public function failPublicationWork(string $errorCode, string $errorDetail = ''): array
    {
        if (! is_string($this->publicationLeaseToken)
            || ! preg_match('/\A[a-f0-9]{64}\z/', $this->publicationLeaseToken)
            || ! preg_match('/\A[a-z0-9_-]{1,64}\z/', $errorCode)
            || strlen($errorDetail) > 1000) {
            throw new RuntimeException('DiscussionBridge publication failure is invalid.');
        }
        $json = json_encode(['publication_work_failure' => [
            'lease_token' => $this->publicationLeaseToken,
            'error_code' => $errorCode,
            'error_detail' => $errorDetail,
        ]], JSON_THROW_ON_ERROR);

        return $this->request('PUT', '/discussion-bridge/v1/publication-work/failure.json', $json);
    }

    public function clearPublicationLease(): void
    {
        $this->publicationLeaseToken = null;
    }

    public function publicationLeaseToken(): ?string
    {
        return $this->publicationLeaseToken;
    }

    public function resumePublicationLease(string $token): void
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            throw new RuntimeException('DiscussionBridge publication lease token is invalid.');
        }
        $this->publicationLeaseToken = $token;
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

    private function request(
        string $method,
        string $path,
        ?string $json = null,
        bool $authenticate = true,
        ?int $maximumResponseBytes = null,
    ): array
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
                'http_errors' => false,
                'stream' => true,
                'headers' => $headers,
                ...($json === null ? [] : ['body' => $json]),
            ]);
        } catch (Throwable) {
            throw new RuntimeException('DiscussionBridge transport failed.');
        }

        $status = $response->getStatusCode();
        if ($status === 429) {
            throw new BridgeRequestException(429, 'rate_limited');
        }
        $data = $this->decode($response, $maximumResponseBytes);
        if ($status < 200 || $status >= 300) {
            $reason = is_string($data['reason'] ?? null) ? substr($data['reason'], 0, 100) : 'request_failed';
            throw new BridgeRequestException($status, $reason);
        }

        return $data;
    }

    private function decode(ResponseInterface $response, ?int $maximumResponseBytes = null): array
    {
        $contentType = strtolower($response->getHeaderLine('Content-Type'));
        if (! str_starts_with($contentType, 'application/json')) {
            throw new RuntimeException('DiscussionBridge response content type is invalid.');
        }

        $maximum = $maximumResponseBytes ?? (int) config('discussionbridge.maximum_response_bytes', 65536);
        if ($maximum < 1 || $maximum > 1024 * 1024) {
            throw new RuntimeException('DiscussionBridge response bound is invalid.');
        }
        $declared = $response->getHeaderLine('Content-Length');
        if ($declared !== '' && (! ctype_digit($declared) || (int) $declared > $maximum)) {
            throw new RuntimeException('DiscussionBridge response is too large.');
        }

        $body = $response->getBody();
        $bytes = '';
        while (! $body->eof()) {
            $bytes .= $body->read(min(8192, $maximum + 1 - strlen($bytes)));
            if (strlen($bytes) > $maximum) {
                throw new RuntimeException('DiscussionBridge response is too large.');
            }
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

    private function assertResourceId(string $resourceId): void
    {
        if (! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $resourceId)) {
            throw new RuntimeException('DiscussionBridge resource ID is invalid.');
        }
    }
}
