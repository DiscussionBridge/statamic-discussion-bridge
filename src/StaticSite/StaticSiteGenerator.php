<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\StaticSite;

use Composer\InstalledVersions;
use RuntimeException;

class StaticSiteGenerator
{
    public function __construct(private readonly PleaseCommandRunner $commands)
    {
    }

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

        $this->commands->run('discussionbridge:ssg-prepare');
        $this->commands->run('ssg:generate');

        return [
            'prepared' => true,
            'generated' => true,
        ];
    }
}
