<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Console;

use CodeWorksLabs\DiscussionBridgeStatamic\Publication\StaticPublicationTransaction;
use Illuminate\Console\Command;
use Throwable;

class FinalizeStaticPublicationWork extends Command
{
    protected $signature = 'discussionbridge:ssg-finalize-publication-work';

    protected $description = 'Verify deployed static publications and acknowledge their exact receiver revisions';

    public function handle(StaticPublicationTransaction $transaction): int
    {
        try {
            $this->line(json_encode($transaction->finalize(), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error(substr($error->getMessage(), 0, 300));

            return self::FAILURE;
        }
    }
}
