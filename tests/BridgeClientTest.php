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
        $this->assertSame('0.2.0-alpha.22', $history[0]['request']->getHeaderLine('X-DiscussionBridge-Adapter-Version'));
    }

    public function test_record_rejects_oversized_response(): void
    {
        $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], str_repeat('x', 65537))]);
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
}
