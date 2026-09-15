<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Console;

use CodeWorksLabs\DiscussionBridgeStatamic\Publication\PublicationSynchronizer;
use Illuminate\Console\Command;

class SyncPublications extends Command
{
    protected $signature = 'discussionbridge:sync-publications';

    protected $description = 'Create or update explicitly authorized native Statamic publications';

    public function handle(PublicationSynchronizer $synchronizer): int
    {
        $summary = $synchronizer->synchronize();
        foreach ($summary['errors'] as $error) {
            $this->error($error);
        }
        $output = $summary;
        unset($output['errors']);
        $this->line(json_encode($output, JSON_THROW_ON_ERROR));

        return $summary['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
