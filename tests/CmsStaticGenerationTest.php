<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

class CmsStaticGenerationTest extends NativeStaticTestCase
{
    use \CodeWorksLabs\DiscussionBridgeStatamic\Tests\Concerns\ExercisesCmsDynamicLifecycle;

    protected function cmsLifecycleKind(): string
    {
        return 'static';
    }

    public static function cmsRecoveryCases(): array
    {
        return [['normal'], ['lost-sync-response'], ['lost-verify-response'], ['native-cache-interruption'],
            ['lost-deployment-receipt'], ['public-verification-restored'], ['restore-unavailable'],
            ['generation-lease-expired'], ['generation-renew-failed']];
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('statamic.ssg.failures', 'warnings');
    }

    protected function cmsStaticPublicDirectory(string $scenario): string
    {
        $directory = dirname(__DIR__).'/.tmp/cms-static-public-'.bin2hex(random_bytes(8));
        mkdir($directory, 0777, true);
        $hook = ['argv' => [PHP_BINARY, '-n', __DIR__.'/fixtures/cms-static-hook.php', $directory, $scenario],
            'cwd' => dirname(__DIR__), 'timeout_seconds' => 10];
        config(['discussionbridge.cms_static_hooks' => ['deploy' => $hook, 'reconcile' => $hook, 'restore' => $hook]]);
        return $directory;
    }
}
