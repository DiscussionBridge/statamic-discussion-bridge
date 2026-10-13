<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use CodeWorksLabs\DiscussionBridgeStatamic\Transport\BridgeClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use RuntimeException;

class BridgeClientTest extends TestCase
{
    public function test_resolve_uses_bounded_json_contract(): void
    {
        $history = [];
        $mock = new MockHandler([new Response(201, ['Content-Type' => 'application/json'], json_encode([
            'outcome' => 'created',
            'resource_id' => '63bad04c-1c2e-4c38-80ce-b379137dbb2c',
            'topic_id' => 7,
            'topic_url' => 'https://forum.example/t/topic/7',
            'direction' => 'to_discourse',
            'core_fallback' => false,
        ], JSON_THROW_ON_ERROR))]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $client = new BridgeClient(new Client(['handler' => $stack]), app(Configuration::class));

        $response = $client->resolve([
            'direction' => 'to_discourse',
            'external_id' => 'statamic-entry:stable',
            'canonical_url' => 'https://statamic.example/page/',
            'title' => 'Page',
            'published' => true,
        ]);

        $this->assertSame('created', $response['outcome']);
        $this->assertSame('statamic-discussion-bridge', $history[0]['request']->getHeaderLine('X-DiscussionBridge-Adapter'));
        $this->assertSame('0.3.0-alpha.1', $history[0]['request']->getHeaderLine('X-DiscussionBridge-Adapter-Version'));
    }

    public function test_record_rejects_oversized_response(): void
    {
        $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], str_repeat('x', 2097153))]);
        $client = new BridgeClient(new Client(['handler' => HandlerStack::create($mock)]), app(Configuration::class));

        $this->expectException(RuntimeException::class);
        $client->record('a4965d46-e657-4af4-af47-6439e544eeb9');
    }

    public function test_public_topic_is_bounded_and_sends_no_bridge_credentials(): void
    {
        $history = [];
        $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'slug' => 'public-topic',
            'post_stream' => ['posts' => []],
        ], JSON_THROW_ON_ERROR))]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $client = new BridgeClient(new Client(['handler' => $stack]), app(Configuration::class));

        $response = $client->publicTopic(42);

        $this->assertSame('public-topic', $response['slug']);
        $this->assertCount(1, $history);
        $this->assertSame('https://forum.example/t/42.json', (string) $history[0]['request']->getUri());
        $this->assertFalse($history[0]['request']->hasHeader('X-DiscussionBridge-Connection'));
        $this->assertFalse($history[0]['request']->hasHeader('X-DiscussionBridge-Secret'));
    }

    public function test_public_topic_rejects_nonpositive_identity_before_request(): void
    {
        $client = new BridgeClient(new Client(['handler' => HandlerStack::create(new MockHandler())]), app(Configuration::class));

        $this->expectException(RuntimeException::class);
        $client->publicTopic(0);
    }

    public function test_public_topic_posts_uses_bounded_public_batch_without_credentials(): void
    {
        $history = [];
        $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'post_stream' => ['posts' => []],
        ], JSON_THROW_ON_ERROR))]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $client = new BridgeClient(new Client(['handler' => $stack]), app(Configuration::class));

        $client->publicTopicPosts(42, [21, 22]);

        $this->assertCount(1, $history);
        $uri = (string) $history[0]['request']->getUri();
        $this->assertStringStartsWith('https://forum.example/t/42/posts.json?', $uri);
        $this->assertStringContainsString('post_ids%5B0%5D=21', $uri);
        $this->assertStringContainsString('post_ids%5B1%5D=22', $uri);
        $this->assertFalse($history[0]['request']->hasHeader('X-DiscussionBridge-Connection'));
        $this->assertFalse($history[0]['request']->hasHeader('X-DiscussionBridge-Secret'));
    }

    public function test_public_topic_posts_rejects_oversized_batch_before_request(): void
    {
        $client = new BridgeClient(new Client(['handler' => HandlerStack::create(new MockHandler())]), app(Configuration::class));

        $this->expectException(RuntimeException::class);
        $client->publicTopicPosts(42, range(1, 21));
    }

    public function test_complete_source_chunks_keep_utf8_digest_and_exact_authenticated_origin(): void
    {
        $body = '<p>'.str_repeat('é', 50000).'Complete ending</p>';
        $record = $this->chunkRecord($body);
        $responses = [];
        for ($offset = 0; $offset < strlen($body); $offset += 32768) {
            $responses[] = new Response(200, ['Content-Type' => 'application/json'], json_encode($this->chunk($record, $body, $offset), JSON_THROW_ON_ERROR));
        }
        $history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));
        $client = new BridgeClient(new Client(['handler' => $stack]), app(Configuration::class));
        $this->assertSame($body, $client->publicationContent($record));
        $this->assertCount(4, $history);
        foreach ($history as $index => $request) {
            $this->assertStringStartsWith('https://forum.example/discussion-bridge/v1/bridge-records/'.$record['resource_id'].'/content.json?', (string) $request['request']->getUri());
            $this->assertStringContainsString('offset='.($index * 32768), (string) $request['request']->getUri());
            $this->assertTrue($request['request']->hasHeader('X-DiscussionBridge-Connection'));
            $this->assertTrue($request['request']->hasHeader('X-DiscussionBridge-Secret'));
        }
    }

    public function test_chunk_identity_base64_continuation_digest_and_utf8_fail_closed(): void
    {
        $body = '<p>Source</p>';
        $record = $this->chunkRecord($body);
        foreach ([['resource_id' => 'wrong'], ['edition' => str_repeat('b', 64)], ['offset' => 1], ['next_offset' => 1], ['chunk_base64' => '!!!!'], ['chunk_base64' => base64_encode(str_repeat('x', strlen($body)))]] as $change) {
            $chunk = array_replace($this->chunk($record, $body, 0), $change);
            $client = new BridgeClient(new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, ['Content-Type' => 'application/json'], json_encode($chunk, JSON_THROW_ON_ERROR))]))]), app(Configuration::class));
            try {
                $client->publicationContent($record);
                $this->fail('Expected invalid content chunk rejection.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
        $invalid = "\xFF";
        $record = $this->chunkRecord($invalid);
        $client = new BridgeClient(new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, ['Content-Type' => 'application/json'], json_encode($this->chunk($record, $invalid, 0), JSON_THROW_ON_ERROR))]))]), app(Configuration::class));
        $this->expectException(RuntimeException::class);
        $client->publicationContent($record);
    }

    public function test_descriptor_cannot_select_a_different_path_or_origin_before_request(): void
    {
        $record = $this->chunkRecord('<p>Source</p>');
        $record['source']['content']['path'] = 'https://other.example/private';
        $client = new BridgeClient(new Client(['handler' => HandlerStack::create(new MockHandler())]), app(Configuration::class));
        $this->expectException(RuntimeException::class);
        $client->publicationContent($record);
    }

    private function chunkRecord(string $body): array
    {
        $resource = '11111111-1111-4111-8111-111111111111';
        return ['resource_id' => $resource, 'content_html' => null, 'source' => ['edition' => str_repeat('a', 64), 'content' => ['bytes' => strlen($body), 'sha256' => hash('sha256', $body), 'path' => '/discussion-bridge/v1/bridge-records/'.$resource.'/content.json', 'chunk_bytes' => 32768]]];
    }

    private function chunk(array $record, string $body, int $offset): array
    {
        $bytes = substr($body, $offset, 32768);
        $next = $offset + strlen($bytes);
        return ['resource_id' => $record['resource_id'], 'edition' => $record['source']['edition'], 'offset' => $offset, 'total_bytes' => strlen($body), 'content_sha256' => hash('sha256', $body), 'chunk_base64' => base64_encode($bytes), 'next_offset' => $next === strlen($body) ? null : $next];
    }
}
