<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\StaticSite;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

class StaticSiteGenerator
{
    public function available(): bool
    {
        return InstalledVersions::isInstalled('statamic/ssg');
    }

    /**
     * @return array{prepared: bool, generated: bool}
     */
    public function generate(): array
    {
        if (! $this->available()) {
            throw new RuntimeException('Static site generation is not active for this Statamic profile.');
        }

        if (Artisan::call('discussionbridge:ssg-prepare', ['--no-interaction' => true]) !== 0) {
            throw new RuntimeException('DiscussionBridge preparation failed. Static generation was not started.');
        }

        if (Artisan::call('ssg:generate', ['--no-interaction' => true]) !== 0) {
            throw new RuntimeException('Statamic static generation failed.');
        }

        return [
            'prepared' => true,
            'generated' => true,
        ];
    }
}
