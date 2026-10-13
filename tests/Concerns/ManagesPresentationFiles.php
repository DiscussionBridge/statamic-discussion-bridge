<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests\Concerns;

use CodeWorksLabs\DiscussionBridgeStatamic\ServiceProvider;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\PresentationAssets;
use Illuminate\Support\ServiceProvider as LaravelServiceProvider;

trait ManagesPresentationFiles
{
    /** @var array<string, string|null> */
    private array $presentationBackups = [];
    private string $presentationRoot;

    protected function isolatePresentationFiles(): void
    {
        $this->presentationRoot = str_replace('\\', '/', $this->app->basePath()).'/';
        foreach (array_values($this->optionalPresentationPaths()) as $target) {
            $this->rememberPresentationFile($target);
            if (is_file($target)) {
                unlink($target);
            }
        }
    }

    /** @return array<string, string> */
    protected function optionalPresentationPaths(): array
    {
        return LaravelServiceProvider::pathsToPublish(ServiceProvider::class, PresentationAssets::RICH_TAG)
            + LaravelServiceProvider::pathsToPublish(ServiceProvider::class, PresentationAssets::TEMPLATES_TAG);
    }

    protected function richTarget(): string
    {
        return array_values(LaravelServiceProvider::pathsToPublish(ServiceProvider::class, PresentationAssets::RICH_TAG))[0];
    }

    protected function writePresentationFile(string $target, string $contents): void
    {
        $this->rememberPresentationFile($target);
        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0777, true);
        }
        file_put_contents($target, $contents);
    }

    private function rememberPresentationFile(string $target): void
    {
        $this->assertStringStartsWith($this->presentationRoot, str_replace('\\', '/', $target));
        $this->assertFalse(is_link($target));
        if (! array_key_exists($target, $this->presentationBackups)) {
            $this->presentationBackups[$target] = is_file($target) ? file_get_contents($target) : null;
        }
    }

    protected function restorePresentationFiles(): void
    {
        foreach ($this->presentationBackups as $target => $contents) {
            if ($contents === null) {
                if (is_file($target)) {
                    unlink($target);
                }
            } else {
                file_put_contents($target, $contents);
            }
        }
        $this->presentationBackups = [];
    }
}
