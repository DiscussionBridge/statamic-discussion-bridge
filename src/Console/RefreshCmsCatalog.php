<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Console;

use CodeWorksLabs\DiscussionBridgeStatamic\Cms\CatalogProducer;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\CmsException;
use Illuminate\Console\Command;

/** Native server administrator operation; never a public/receiver control route. */
class RefreshCmsCatalog extends Command
{
    protected $signature = 'discussionbridge:cms-catalog {kind : authors or taxonomy}';
    protected $description = 'Refresh the configured native DiscussionBridge collection catalog';

    public function handle(CatalogProducer $producer): int
    {
        try {
            $this->line(json_encode($producer->refresh((string) $this->argument('kind')), JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error instanceof CmsException ? $error->getMessage()
                : 'DiscussionBridge native catalog binding or typed response requires attention.');
            return self::FAILURE;
        }
    }
}
