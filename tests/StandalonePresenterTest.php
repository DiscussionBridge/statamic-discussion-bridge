<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\HtmlSanitizer;
use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\PresentationChrome;
use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\StandalonePresenter;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use CodeWorksLabs\DiscussionBridgeStatamic\Transport\BridgeClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

class StandalonePresenterTest extends TestCase
{
    public function test_simple_renders_public_replies_but_not_the_first_post(): void
    {
        $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'slug' => 'public-topic',
            'post_stream' => ['posts' => [
                ['post_number' => 1, 'username' => 'publisher', 'cooked' => '<p>Source article</p>'],
                ['post_number' => 2, 'username' => 'reader', 'name' => 'Demo Reader', 'cooked' => '<p>Useful reply</p><script>bad()</script>'],
            ]],
        ], JSON_THROW_ON_ERROR))]);
        $configuration = app(Configuration::class);
        $presenter = new StandalonePresenter(
            new BridgeClient(new Client(['handler' => HandlerStack::create($mock)]), $configuration),
            $configuration,
            new HtmlSanitizer(),
            new PresentationChrome(),
        );

        $html = $presenter->simple(42);

        $this->assertStringContainsString('<h2>Comments</h2>', $html);
        $this->assertStringContainsString('Demo Reader', $html);
        $this->assertStringContainsString('Useful reply', $html);
        $this->assertStringContainsString('https://forum.example/t/public-topic/42/2', $html);
        $this->assertStringNotContainsString('Source article', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_simple_shows_an_empty_state_when_topic_has_no_replies(): void
    {
        $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'slug' => 'quiet-topic',
            'post_stream' => ['posts' => [
                ['post_number' => 1, 'username' => 'publisher', 'cooked' => '<p>Source article</p>'],
            ]],
        ], JSON_THROW_ON_ERROR))]);
        $configuration = app(Configuration::class);
        $presenter = new StandalonePresenter(
            new BridgeClient(new Client(['handler' => HandlerStack::create($mock)]), $configuration),
            $configuration,
            new HtmlSanitizer(),
            new PresentationChrome(),
        );

        $this->assertStringContainsString('No replies yet.', $presenter->simple(7));
    }
}
