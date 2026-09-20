<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Console;

use CodeWorksLabs\DiscussionBridgeStatamic\Publication\PlatformCatalog;
use CodeWorksLabs\DiscussionBridgeStatamic\Transport\BridgeClient;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class RefreshPlatformCatalog extends Command
{
    protected $signature = 'discussionbridge:refresh-platform-catalog';

    protected $description = 'Upload the bounded native Statamic publication catalog to The Bridge';

    public function handle(BridgeClient $client, PlatformCatalog $catalog): int
    {
        try {
            $current = $client->platformCatalogStatus();
            $revision = $current['catalog_revision'] ?? null;
            $response = $client->updatePlatformCatalog(
                $catalog->build(),
                is_string($revision) && preg_match('/\A[a-f0-9]{64}\z/', $revision) ? $revision : null,
            );
            if (! is_string($response['catalog_revision'] ?? null)
                || ! preg_match('/\A[a-f0-9]{64}\z/', $response['catalog_revision'])) {
                throw new RuntimeException('The Bridge returned an invalid Statamic catalog identity.');
            }
            $this->line(json_encode([
                'catalog_revision' => $response['catalog_revision'],
                'destination_mapping_state' => $response['destination_mapping_state'] ?? null,
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error(substr($error->getMessage(), 0, 300));

            return self::FAILURE;
        }
    }
}
