<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use CodeWorksLabs\DiscussionBridgeStatamic\Transport\BridgeClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use RuntimeException;

class BridgeClientTest extends TestCase
{
    public function test_resolve_uses_bounded_json_contract(): void
    {
        $mock = new MockHandler([new Response(201, ['Content-Type' => 'application/json'], json_encode([
            'outcome' => 'created',
            'resource_id' => '63bad04c-1c2e-4c38-80ce-b379137dbb2c',
            'topic_id' => 7,
            'topic_url' => 'https://forum.example/t/topic/7',
            'direction' => 'to_discourse',
            'core_fallback' => false,
        ], JSON_THROW_ON_ERROR))]);
        $client = new BridgeClient(new Client(['handler' => HandlerStack::create($mock)]), app(Configuration::class));

        $response = $client->resolve([
            'direction' => 'to_discourse',
            'external_id' => 'statamic-entry:stable',
            'canonical_url' => 'https://statamic.example/page/',
            'title' => 'Page',
            'published' => true,
        ]);

        $this->assertSame('created', $response['outcome']);
    }

    public function test_record_rejects_oversized_response(): void
    {
        $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], str_repeat('x', 65537))]);
        $client = new BridgeClient(new Client(['handler' => HandlerStack::create($mock)]), app(Configuration::class));

        $this->expectException(RuntimeException::class);
        $client->record('a4965d46-e657-4af4-af47-6439e544eeb9');
    }
}
