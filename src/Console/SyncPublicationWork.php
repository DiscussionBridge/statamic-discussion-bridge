<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Console;

use CodeWorksLabs\DiscussionBridgeStatamic\Publication\PublicationSynchronizer;
use Illuminate\Console\Command;

class SyncPublicationWork extends Command
{
    protected $signature = 'discussionbridge:sync-publication-work {--limit=8 : Maximum receiver-owned work items to process}';

    protected $description = 'Process bounded incremental From Discourse publication work';

    public function handle(PublicationSynchronizer $synchronizer): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 8],
        ]);
        if (! is_int($limit)) {
            $this->error('Limit must be 1 through 8.');

            return self::INVALID;
        }
        $summary = $synchronizer->synchronizeQueued($limit, 300);
        foreach ($summary['errors'] as $error) {
            $this->error($error);
        }
        $output = $summary;
        unset($output['errors']);
        $this->line(json_encode($output, JSON_THROW_ON_ERROR));

        return $summary['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
