<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

abstract class NativeStaticTestCase extends NativeFlatTestCase
{
    use \CodeWorksLabs\DiscussionBridgeStatamic\Tests\Concerns\ManagesPresentationFiles;

    private string $destination;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set(['app.url' => 'https://statamic.example', 'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'statamic.editions.pro' => true, 'statamic.ssg.base_url' => 'https://statamic.example',
            'statamic.ssg.destination' => $this->destination, 'statamic.ssg.copy' => [], 'statamic.ssg.symlinks' => [],
            'statamic.ssg.urls' => [], 'statamic.ssg.exclude' => [], 'statamic.ssg.failures' => 'warnings']);
    }

    protected function getPackageProviders($app)
    {
        return array_merge(parent::getPackageProviders($app), [\Wilderborn\Partyline\ServiceProvider::class, \Statamic\StaticSite\ServiceProvider::class]);
    }

    protected function setUp(): void
    {
        $root = dirname(__DIR__).'/.tmp/native-ssg-'.bin2hex(random_bytes(8));
        mkdir($root.'/output', 0700, true);
        $this->destination = realpath($root.'/output');
        parent::setUp();
        $this->isolatePresentationFiles();
        $name = 'discussionbridge-native-ssg-layout-'.bin2hex(random_bytes(8));
        $this->writePresentationFile(resource_path('views/'.$name.'.antlers.html'), '<!doctype html><html><body>{{ template_content }}</body></html>');
        \Statamic\Facades\Collection::find('discussionbridge')->layout($name)->save();
        $this->assertStringStartsWith(str_replace('\\', '/', realpath(dirname(__DIR__).'/.tmp')).'/', str_replace('\\', '/', $this->destination));
    }

    protected function tearDown(): void
    {
        $this->restorePresentationFiles();
        parent::tearDown();
    }
}
