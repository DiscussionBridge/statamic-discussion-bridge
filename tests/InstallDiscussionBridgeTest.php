<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Transport\BridgeClient;
use Mockery;
use CodeWorksLabs\DiscussionBridgeStatamic\Tests\Concerns\ManagesPresentationFiles;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\PresentationAssets;

class InstallDiscussionBridgeTest extends TestCase
{
    use ManagesPresentationFiles;
    private string $installationRoot;
    private string $originalRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolatePresentationFiles();
        $this->originalRoot = $this->app->basePath();
        $this->installationRoot = sys_get_temp_dir().'/discussionbridge-statamic-install-'.bin2hex(random_bytes(6));
        mkdir($this->installationRoot.'/bootstrap/cache', 0777, true);
        mkdir($this->installationRoot.'/storage/app', 0777, true);
        mkdir($this->installationRoot.'/storage/framework/cache', 0777, true);
        file_put_contents($this->installationRoot.'/.env', "APP_NAME=Statamic\nAPP_URL=https://old.example\n");
        $this->app->setBasePath($this->installationRoot);
    }

    protected function tearDown(): void
    {
        $this->app->setBasePath($this->originalRoot);
        $this->restorePresentationFiles();
        $this->removeTree($this->installationRoot);
        parent::tearDown();
    }

    public function test_guided_installer_writes_protected_configuration_and_verifies_connection(): void
    {
        $client = Mockery::mock(BridgeClient::class);
        $client->shouldReceive('records')->once()->andReturn([
            'bridge_records' => [],
            'pagination' => ['page' => 1, 'pages' => 1, 'total' => 0, 'snapshot' => 'install-verification'],
        ]);
        $this->app->instance(BridgeClient::class, $client);

        $this->artisan('discussionbridge:install', [
            '--forum-url' => 'https://forum.example',
            '--site-origin' => 'https://statamic.example',
            '--connection-id' => 'dbc_0123456789abcdef01234567',
            '--lane' => 'statamic-flat-alpha',
            '--collections' => 'pages,articles',
            '--source-author-name' => 'Statamic Editor',
            '--source-author-profile-url' => 'https://statamic.example/authors/editor',
        ])->expectsQuestion('Content Connection secret', str_repeat('x', 40))
            ->expectsOutputToContain('DiscussionBridge connection configuration verified.')
            ->expectsOutputToContain('Adapter version: 0.3.0-alpha.1')
            ->assertSuccessful();

        $secretPath = storage_path('app/discussionbridge/connection-secret');
        $this->assertSame(str_repeat('x', 40).PHP_EOL, file_get_contents($secretPath));
        $environment = file_get_contents($this->installationRoot.'/.env');
        $this->assertStringContainsString('APP_NAME=Statamic', $environment);
        $this->assertStringContainsString('DISCUSSIONBRIDGE_ENABLED="true"', $environment);
        $this->assertStringContainsString('DISCUSSIONBRIDGE_CONNECTION_ID="dbc_0123456789abcdef01234567"', $environment);
        $this->assertStringContainsString('DISCUSSIONBRIDGE_SECRET_FILE="'.str_replace('\\', '\\\\', $secretPath).'"', $environment);
        $this->assertStringContainsString('DISCUSSIONBRIDGE_COLLECTIONS="pages,articles"', $environment);
        $this->assertNotEmpty(glob($this->installationRoot.'/.env.discussionbridge-backup-*'));
        $this->assertStringNotContainsString('DISCUSSIONBRIDGE_RICH_CONTENT', $environment);
        foreach ($this->optionalPresentationPaths() as $target) {
            $this->assertFileDoesNotExist($target);
        }
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

    public function test_installer_cannot_claim_success_for_incomplete_selected_native_wiring(): void
    {
        $client = Mockery::mock(BridgeClient::class);
        $client->shouldReceive('records')->once()->andReturn([
            'bridge_records' => [], 'pagination' => ['page' => 1, 'pages' => 1, 'total' => 0, 'snapshot' => 'install-verification'],
        ]);
        $this->app->instance(BridgeClient::class, $client);
        $integration = Mockery::mock(\CodeWorksLabs\DiscussionBridgeStatamic\Support\TemplateIntegration::class);
        $integration->shouldReceive('inspect')->once()->andReturn(['checked' => 1, 'incomplete' => ['from_discourse full template operator-selected: article/discussion invocation missing.']]);
        $this->app->instance(\CodeWorksLabs\DiscussionBridgeStatamic\Support\TemplateIntegration::class, $integration);
        $this->artisan('discussionbridge:install', [
            '--forum-url' => 'https://forum.example', '--site-origin' => 'https://statamic.example',
            '--connection-id' => 'dbc_0123456789abcdef01234567', '--lane' => 'statamic-flat-alpha', '--collections' => 'pages',
            '--source-author-name' => 'Statamic Editor', '--source-author-profile-url' => 'https://statamic.example/authors/editor',
        ])->expectsQuestion('Content Connection secret', str_repeat('x', 40))
            ->expectsOutputToContain('selected native presentation integration is incomplete')
            ->expectsOutputToContain('operator-selected')
            ->doesntExpectOutputToContain('DiscussionBridge selected native template wiring verified.')
            ->assertFailed();
    }

    public function test_installer_rejects_each_forbidden_origin_component_before_secret_entry(): void
    {
        foreach (['https://user@forum.example', 'https://forum.example?query=1', 'https://forum.example#fragment', 'https://forum.example/path', "https://forum.example\\evil"] as $origin) {
            $this->artisan('discussionbridge:install', [
                '--forum-url' => $origin,
                '--site-origin' => 'https://statamic.example',
            ])->expectsOutputToContain('DiscussionBridge origins')->assertFailed();
        }
    }

    public function test_optional_installer_selection_publishes_without_resetting_operator_choices(): void
    {
        $client = Mockery::mock(BridgeClient::class);
        $client->shouldReceive('records')->twice()->andReturn([
            'bridge_records' => [],
            'pagination' => ['page' => 1, 'pages' => 1, 'total' => 0, 'snapshot' => 'install-verification'],
        ]);
        $this->app->instance(BridgeClient::class, $client);
        $options = [
            '--forum-url' => 'https://forum.example',
            '--site-origin' => 'https://statamic.example',
            '--connection-id' => 'dbc_0123456789abcdef01234567',
            '--lane' => 'statamic-flat-alpha',
            '--collections' => 'pages',
            '--source-author-name' => 'Statamic Editor',
            '--source-author-profile-url' => 'https://statamic.example/authors/editor',
        ];
        $this->artisan('discussionbridge:install', $options + ['--rich-content' => true, '--templates' => true])
            ->expectsQuestion('Content Connection secret', str_repeat('x', 40))->assertSuccessful();
        PresentationAssets::verifyRich();
        foreach ($this->optionalPresentationPaths() as $target) {
            $this->assertFileExists($target);
        }
        $this->assertStringContainsString('DISCUSSIONBRIDGE_RICH_CONTENT="true"', file_get_contents($this->installationRoot.'/.env'));
        $partial = array_values(\Illuminate\Support\ServiceProvider::pathsToPublish(\CodeWorksLabs\DiscussionBridgeStatamic\ServiceProvider::class, PresentationAssets::TEMPLATES_TAG))[0];
        $this->writePresentationFile($partial, 'Operator customized partial');
        $this->artisan('discussionbridge:install', $options)
            ->expectsQuestion('Content Connection secret', str_repeat('x', 40))->assertSuccessful();
        $this->assertTrue(PresentationAssets::selected());
        $this->assertStringContainsString('DISCUSSIONBRIDGE_RICH_CONTENT="true"', file_get_contents($this->installationRoot.'/.env'));
        $this->assertSame('Operator customized partial', file_get_contents($partial));
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
