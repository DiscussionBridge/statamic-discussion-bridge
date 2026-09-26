<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Publication\PlatformCatalog;
use CodeWorksLabs\DiscussionBridgeStatamic\Transport\BridgeClient;
use Mockery;

class InstallDiscussionBridgeTest extends TestCase
{
    private string $installationRoot;
    private string $originalRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalRoot = $this->app->basePath();
        $this->installationRoot = sys_get_temp_dir().'/discussionbridge-statamic-install-'.bin2hex(random_bytes(6));
        mkdir($this->installationRoot.'/bootstrap/cache', 0777, true);
        mkdir($this->installationRoot.'/storage/app', 0777, true);
        file_put_contents($this->installationRoot.'/.env', "APP_NAME=Statamic\nAPP_URL=https://old.example\n");
        $this->app->setBasePath($this->installationRoot);
    }

    protected function tearDown(): void
    {
        $this->app->setBasePath($this->originalRoot);
        $this->removeTree($this->installationRoot);
        parent::tearDown();
    }

    public function test_guided_installer_writes_protected_configuration_and_verifies_connection(): void
    {
        $client = Mockery::mock(BridgeClient::class);
        $client->shouldReceive('platformCatalogStatus')->once()->andReturn(['catalog_revision' => null]);
        $client->shouldReceive('updatePlatformCatalog')->once()->andReturn([
            'catalog_revision' => str_repeat('a', 64),
            'destination_mapping_state' => 'missing',
        ]);
        $client->shouldReceive('records')->once()->andReturn([
            'bridge_records' => [],
            'pagination' => ['page' => 1, 'pages' => 1, 'total' => 0, 'snapshot' => 'install-verification'],
        ]);
        $this->app->instance(BridgeClient::class, $client);
        $catalog = Mockery::mock(PlatformCatalog::class);
        $catalog->shouldReceive('build')->once()->andReturn([
            'schema_version' => 1,
            'platform' => 'statamic',
            'containers' => [],
            'taxonomies' => [],
            'authors' => [],
        ]);
        $this->app->instance(PlatformCatalog::class, $catalog);

        $this->artisan('discussionbridge:install', [
            '--forum-url' => 'https://forum.example',
            '--site-origin' => 'https://statamic.example',
            '--connection-id' => 'dbc_0123456789abcdef01234567',
            '--lane' => 'statamic-flat-alpha',
            '--collections' => 'pages,articles',
            '--native-author-id' => 'statamic-service-user',
            '--source-author-name' => 'Statamic Editor',
            '--source-author-profile-url' => 'https://statamic.example/authors/editor',
        ])->expectsQuestion('Content Connection secret', str_repeat('x', 40))
            ->expectsOutputToContain('DiscussionBridge installation verified.')
            ->expectsOutputToContain('Adapter version: 0.2.0-alpha.48')
            ->assertSuccessful();

        $secretPath = storage_path('app/discussionbridge/connection-secret');
        $this->assertSame(str_repeat('x', 40).PHP_EOL, file_get_contents($secretPath));
        $environment = file_get_contents($this->installationRoot.'/.env');
        $this->assertStringContainsString('APP_NAME=Statamic', $environment);
        $this->assertStringContainsString('DISCUSSIONBRIDGE_ENABLED="true"', $environment);
        $this->assertStringContainsString('DISCUSSIONBRIDGE_CONNECTION_ID="dbc_0123456789abcdef01234567"', $environment);
        $this->assertStringContainsString('DISCUSSIONBRIDGE_SECRET_FILE="'.str_replace('\\', '\\\\', $secretPath).'"', $environment);
        $this->assertStringContainsString('DISCUSSIONBRIDGE_COLLECTIONS="pages,articles"', $environment);
        $this->assertStringContainsString('DISCUSSIONBRIDGE_NATIVE_AUTHOR_ID="statamic-service-user"', $environment);
        $this->assertNotEmpty(glob($this->installationRoot.'/.env.discussionbridge-backup-*'));
    }

    public function test_guided_installer_rejects_an_invalid_connection_id_before_secret_entry(): void
    {
        $this->artisan('discussionbridge:install', [
            '--forum-url' => 'https://forum.example',
            '--site-origin' => 'https://statamic.example',
            '--connection-id' => 'not-a-connection',
        ])->expectsOutputToContain('Content Connection ID must use the dbc_ identifier shown by The Bridge.')
            ->assertFailed();
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $member) {
            if ($member === '.' || $member === '..') {
                continue;
            }
            $target = $path.'/'.$member;
            if (is_dir($target) && ! is_link($target)) {
                $this->removeTree($target);
            } else {
                @unlink($target);
            }
        }
        @rmdir($path);
    }
}
