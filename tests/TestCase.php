<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\ServiceProvider;
use Statamic\Testing\AddonTestCase;

abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;
    private string $fixtureSecret;
    private string $fixtureState;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('discussionbridge', [
            'enabled' => true, 'forum_url' => 'https://forum.example', 'site_origin' => 'https://statamic.example',
            'connection_id' => 'dbc_0123456789abcdef01234567', 'secret_file' => $this->fixtureSecret,
            'lane' => 'statamic-alpha', 'collections' => ['pages'], 'source_author_name' => 'Statamic Author',
            'source_author_profile_url' => 'https://statamic.example/authors/statamic-author', 'rich_content' => false,
            'adapter_id' => 'statamic-discussion-bridge', 'adapter_version' => 'native-fixture',
            'connect_timeout_seconds' => 2, 'response_timeout_seconds' => 5, 'maximum_response_bytes' => 65536,
            'presentation_cache_seconds' => 60, 'worker_batch_limit' => 25, 'cms_state_directory' => $this->fixtureState,
        ]);
    }

    protected function setUp(): void
    {
        $fixture = dirname(__DIR__).'/.tmp/testcase-'.bin2hex(random_bytes(8));
        mkdir($fixture, 0700, true);
        $this->fixtureSecret = $fixture.'/fixture-secret';
        $this->fixtureState = $fixture.'/private-state';
        file_put_contents($this->fixtureSecret, str_repeat('s', 40));
        parent::setUp();
        (new \ReflectionProperty(\CodeWorksLabs\DiscussionBridgeStatamic\Transport\NativeForumClient::class, 'brandingCache'))->setValue(null, []);
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        @unlink($this->fixtureSecret);
        try {
            parent::tearDown();
        } finally {
            // Console callbacks are process-static; keep native SSG command
            // providers confined to the fixture app that registered them.
            \Illuminate\Console\Application::forgetBootstrappers();
        }
    }
}
