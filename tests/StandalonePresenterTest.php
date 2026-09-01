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
                ['id' => 1, 'post_number' => 1, 'username' => 'publisher', 'cooked' => '<p>Source article</p>'],
                ['id' => 2, 'post_number' => 2, 'username' => 'reader', 'name' => 'Demo Reader', 'created_at' => '2026-08-31T12:00:00Z', 'avatar_template' => '/user_avatar/forum.example/reader/{size}/1_2.png', 'cooked' => '<p>Useful reply</p><script>bad()</script>'],
            ], 'stream' => [1, 2]],
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
        $this->assertStringContainsString('https://forum.example/user_avatar/forum.example/reader/48/1_2.png', $html);
        $this->assertStringContainsString('<time datetime="2026-08-31T12:00:00+00:00">Aug 31, 2026</time>', $html);
        $this->assertStringNotContainsString('reply 2', $html);
        $this->assertStringNotContainsString('Source article', $html);
        $this->assertStringNotContainsString('<script>bad()', $html);
        $this->assertStringContainsString('data-discussionbridge-simple-live', $html);
        $this->assertStringContainsString('data-discourse-origin="https://forum.example"', $html);
        $this->assertStringContainsString('data-topic-id="42"', $html);
        $this->assertStringContainsString('data-topic-url="https://forum.example/t/public-topic/42"', $html);
        $this->assertStringContainsString('credentials:"omit"', $html);
        $this->assertStringContainsString('Showing the saved comment snapshot', $html);
        $this->assertStringContainsString('Open the discussion for current replies', $html);
        $this->assertStringNotContainsString('X-DiscussionBridge-Secret', $html);
    }

    public function test_simple_shows_an_empty_state_when_topic_has_no_replies(): void
    {
        $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'slug' => 'quiet-topic',
            'post_stream' => ['posts' => [
                ['id' => 1, 'post_number' => 1, 'username' => 'publisher', 'cooked' => '<p>Source article</p>'],
            ], 'stream' => [1]],
        ], JSON_THROW_ON_ERROR))]);
        $configuration = app(Configuration::class);
        $presenter = new StandalonePresenter(
            new BridgeClient(new Client(['handler' => HandlerStack::create($mock)]), $configuration),
            $configuration,
            new HtmlSanitizer(),
            new PresentationChrome(),
        );

        $this->assertStringContainsString('No comments yet.', $presenter->simple(7));
    }

    public function test_simple_browser_source_is_bounded_sanitized_and_credential_free(): void
    {
        $source = file_get_contents(__DIR__.'/../resources/js/browser-simple.mjs');

        $this->assertIsString($source);
        $this->assertStringContainsString('credentials: "omit"', $source);
        $this->assertStringContainsString('redirect: "error"', $source);
        $this->assertStringContainsString('DOMPurify.sanitize', $source);
        $this->assertStringContainsString('MAX_REPLIES = 50', $source);
        $this->assertStringContainsString('INITIAL_REPLIES = 5', $source);
        $this->assertStringContainsString('discussionbridgeSimpleState = "snapshot"', $source);
        $this->assertStringNotContainsString('X-DiscussionBridge', $source);
        $this->assertStringNotContainsString('connectionSecret', $source);
    }

    public function test_interactive_topic_uses_the_full_app_without_receiver_credentials(): void
    {
        $configuration = app(Configuration::class);
        $presenter = new StandalonePresenter(
            new BridgeClient(new Client(), $configuration),
            $configuration,
            new HtmlSanitizer(),
            new PresentationChrome(),
        );

        $html = $presenter->interactiveTopic(56);

        $this->assertStringContainsString('"topicId":56', $html);
        $this->assertStringContainsString('"fullApp":true', $html);
        $this->assertStringContainsString('https://forum.example/t/56', $html);
        $this->assertStringNotContainsString('X-DiscussionBridge', $html);
    }

    public function test_simple_fetches_missing_batches_and_discloses_replies_after_five(): void
    {
        $firstPosts = [
            ['id' => 1, 'post_number' => 1, 'username' => 'publisher', 'cooked' => '<p>Source article</p>'],
            ['id' => 2, 'post_number' => 2, 'username' => 'reader2', 'created_at' => '2026-08-31T12:00:00Z', 'cooked' => '<p>Reply 2 body</p>'],
        ];
        $additionalPosts = [];
        foreach (range(3, 8) as $id) {
            $additionalPosts[] = ['id' => $id, 'post_number' => $id, 'username' => 'reader'.$id, 'created_at' => '2026-08-31T12:00:00Z', 'cooked' => '<p>Reply '.$id.' body</p>'];
        }
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'slug' => 'long-topic',
                'post_stream' => ['posts' => $firstPosts, 'stream' => range(1, 8)],
            ], JSON_THROW_ON_ERROR)),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'post_stream' => ['posts' => $additionalPosts],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $configuration = app(Configuration::class);
        $presenter = new StandalonePresenter(
            new BridgeClient(new Client(['handler' => HandlerStack::create($mock)]), $configuration),
            $configuration,
            new HtmlSanitizer(),
            new PresentationChrome(),
        );

        $html = $presenter->simple(42);

        $this->assertStringContainsString('Reply 8 body', $html);
        $this->assertStringContainsString('<details class="discussionbridge-simple__more">', $html);
        $this->assertStringContainsString('Show 2 more comments', $html);
        $this->assertStringContainsString('Show fewer comments', $html);
    }
}
