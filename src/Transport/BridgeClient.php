<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Transport;

use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

class BridgeClient
{
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

    public function records(int $page = 1): array
    {
        if ($page < 1 || $page > 10000) {
            throw new RuntimeException('DiscussionBridge records page is invalid.');
        }

        return $this->request('GET', '/discussion-bridge/v1/bridge-records.json?page='.$page);
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

    private function request(string $method, string $path, ?string $json = null, bool $authenticate = true): array
    {
        $headers = ['Accept' => 'application/json'];
        if ($authenticate) {
            $headers['X-DiscussionBridge-Connection'] = $this->configuration->connectionId();
            $headers['X-DiscussionBridge-Secret'] = $this->configuration->secret();
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

        $data = $this->decode($response);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $reason = is_string($data['reason'] ?? null) ? substr($data['reason'], 0, 100) : 'request_failed';
            throw new BridgeRequestException($status, $reason);
        }

        return $data;
    }

    private function decode(ResponseInterface $response): array
    {
        $contentType = strtolower($response->getHeaderLine('Content-Type'));
        if (! str_starts_with($contentType, 'application/json')) {
            throw new RuntimeException('DiscussionBridge response content type is invalid.');
        }

        $maximum = (int) config('discussionbridge.maximum_response_bytes', 65536);
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
}
