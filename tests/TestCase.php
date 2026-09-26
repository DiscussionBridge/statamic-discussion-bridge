<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\ServiceProvider;
use CodeWorksLabs\DiscussionBridgeStatamic\Version;
use Statamic\Testing\AddonTestCase;

abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('discussionbridge', [
            'enabled' => true,
            'forum_url' => 'https://forum.example',
            'site_origin' => 'https://statamic.example',
            'connection_id' => 'dbc_0123456789abcdef01234567',
            'secret_file' => sys_get_temp_dir().'/discussionbridge-statamic-test-secret',
            'lane' => 'statamic-alpha',
            'collections' => ['pages'],
            'native_author_id' => 'statamic-service-user',
            'ssg_transaction_file' => sys_get_temp_dir().'/discussionbridge-statamic-ssg-transaction.json',
            'source_author_name' => 'Statamic Author',
            'source_author_profile_url' => 'https://statamic.example/authors/statamic-author',
            'adapter_id' => 'statamic-discussion-bridge',
            'adapter_version' => Version::PROTOCOL,
            'connect_timeout_seconds' => 2,
            'response_timeout_seconds' => 5,
            'maximum_response_bytes' => 65536,
            'presentation_cache_seconds' => 60,
            'worker_batch_limit' => 25,
        ]);
    }

    protected function setUp(): void
    {
        @unlink(sys_get_temp_dir().'/discussionbridge-statamic-ssg-transaction.json');
        @unlink(sys_get_temp_dir().'/discussionbridge-statamic-ssg-transaction.json.lock');
        file_put_contents(sys_get_temp_dir().'/discussionbridge-statamic-test-secret', str_repeat('s', 40));
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        @unlink(sys_get_temp_dir().'/discussionbridge-statamic-test-secret');
        @unlink(sys_get_temp_dir().'/discussionbridge-statamic-ssg-transaction.json');
        @unlink(sys_get_temp_dir().'/discussionbridge-statamic-ssg-transaction.json.lock');
        parent::tearDown();
    }
}
