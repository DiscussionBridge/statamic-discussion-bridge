<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\HtmlSanitizer;
use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\PresentationChrome;
use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\StandalonePresenter;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use CodeWorksLabs\DiscussionBridgeStatamic\Transport\NativeForumClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use RuntimeException;

class ForumNameTest extends TestCase
{
    public function test_native_config_mapping_preserves_literal_environment_labels(): void
    {
        $autoload = dirname((new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 2).'/autoload.php';
        $configuration = dirname(__DIR__).'/config/discussionbridge.php';
        $code = 'require '.var_export($autoload, true).'; $configuration = require '.var_export($configuration, true).'; echo json_encode($configuration["forum_name"], JSON_THROW_ON_ERROR);';
        foreach (['false', 'null', '  literal label  ', ''] as $value) {
            $process = new \Symfony\Component\Process\Process([PHP_BINARY, '-c', (string) php_ini_loaded_file(), '-r', $code], dirname(__DIR__), ['DISCUSSIONBRIDGE_FORUM_NAME' => $value]);
            $process->mustRun();
            $this->assertSame($value, json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR));
        }
    }

    public function test_optional_override_preserves_label_and_never_fetches_a_native_title(): void
    {
        $client = new NativeForumClient(new Client(['handler' => HandlerStack::create(new MockHandler())]), app(Configuration::class));
        config(['discussionbridge.forum_name' => '  Community <members> & readers  ']);
        $this->assertSame('  Community <members> & readers  ', $client->forumName());
        config(['discussionbridge.forum_name' => 'false']);
        $this->assertSame('false', $client->forumName());
        config(['discussionbridge.forum_name' => 'null']);
        $this->assertSame('null', $client->forumName());
    }

    public function test_invalid_explicit_override_is_attention_without_native_fallback(): void
    {
        config(['discussionbridge.forum_name' => '   ']);
        $client = new NativeForumClient(new Client(['handler' => HandlerStack::create(new MockHandler())]), app(Configuration::class));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('forum_configuration_invalid');
        $client->forumName();
    }

    public function test_unset_uses_native_preload_title_with_bounded_credential_free_cached_request(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([$this->settingsResponse('  Native <forum> & members  ')]));
        $stack->push(Middleware::history($history));
        $client = new NativeForumClient(new Client(['handler' => $stack]), app(Configuration::class));
        config(['discussionbridge.forum_name' => null, 'discussionbridge.connection_id' => null, 'discussionbridge.secret_file' => null]);
        $this->assertSame('  Native <forum> & members  ', $client->forumName());
        $this->assertTrue($client->publicPoweredByDiscourse());
        $this->assertCount(1, $history);
        $this->assertSame('https://forum.example/', (string) $history[0]['request']->getUri());
        $this->assertFalse($history[0]['request']->hasHeader('X-DiscussionBridge-Connection'));
        $this->assertFalse($history[0]['request']->hasHeader('X-DiscussionBridge-Secret'));
        $this->assertFalse($history[0]['options']['allow_redirects']);
        $this->assertSame(5.0, $history[0]['options']['timeout']);
    }

    public function test_invalid_native_title_reports_attention_without_hostname_substitution(): void
    {
        $client = new NativeForumClient(new Client(['handler' => HandlerStack::create(new MockHandler([$this->settingsResponse("Native\u{0085}forum")]))]), app(Configuration::class));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('forum_configuration_invalid');
        $client->forumName();
    }

    public function test_unavailable_native_title_keeps_plugin_free_full_in_attention(): void
    {
        $client = new NativeForumClient(new Client(['handler' => HandlerStack::create(new MockHandler([new Response(503)]))]), app(Configuration::class));
        $presenter = new StandalonePresenter($client, app(Configuration::class), new HtmlSanitizer(), new PresentationChrome());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('forum_configuration_invalid');
        $presenter->full('https://statamic.example/source');
    }

    public function test_override_labels_full_and_interactive_without_bridge_or_bootstrap_dependency(): void
    {
        config(['discussionbridge.forum_name' => '<Configured & community>', 'discussionbridge.connection_id' => null, 'discussionbridge.secret_file' => null]);
        $client = new NativeForumClient(new Client(['handler' => HandlerStack::create(new MockHandler())]), app(Configuration::class));
        $presenter = new StandalonePresenter($client, app(Configuration::class), new HtmlSanitizer(), new PresentationChrome());
        $full = $presenter->full('https://statamic.example/source');
        $topic = $presenter->fullTopic(53);
        $interactive = $presenter->interactiveTopic(53);
        foreach ([$full, $topic, $interactive] as $html) {
            $this->assertStringContainsString('&lt;Configured &amp; community&gt;', $html);
            $this->assertStringNotContainsString('X-DiscussionBridge', $html);
        }
        $this->assertStringContainsString('"discourseEmbedUrl":"https://statamic.example/source"', $full);
        $this->assertStringNotContainsString('"topicId"', $full);
        $this->assertStringNotContainsString('"fullApp"', $topic);
        $this->assertStringContainsString('"fullApp":true', $interactive);
    }

    private function settingsResponse(mixed $title): Response
    {
        $settings = json_encode(['title' => $title, 'enable_powered_by_discourse' => true], JSON_THROW_ON_ERROR);
        $preload = json_encode(['siteSettings' => $settings], JSON_THROW_ON_ERROR);

        return new Response(200, ['Content-Type' => 'text/html'], '<script id="data-preloaded">'.$preload.'</script>');
    }
}
