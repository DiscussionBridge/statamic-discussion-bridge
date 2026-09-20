<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Console;

use CodeWorksLabs\DiscussionBridgeStatamic\Publication\StaticPublicationTransaction;
use Illuminate\Console\Command;
use Throwable;

class AbortStaticPublicationWork extends Command
{
    protected $signature = 'discussionbridge:ssg-abort-publication-work';

    protected $description = 'Restore prepared static publications and return their work to receiver attention';

    public function handle(StaticPublicationTransaction $transaction): int
    {
        try {
            $this->line(json_encode($transaction->abort(), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error(substr($error->getMessage(), 0, 300));

            return self::FAILURE;
        }
    }
}
