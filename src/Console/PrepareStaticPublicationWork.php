<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Console;

use CodeWorksLabs\DiscussionBridgeStatamic\Publication\StaticPublicationTransaction;
use Illuminate\Console\Command;
use Throwable;

class PrepareStaticPublicationWork extends Command
{
    protected $signature = 'discussionbridge:ssg-prepare-publication-work {--limit=20 : Maximum receiver-owned work items to prepare}';

    protected $description = 'Prepare bounded From Discourse work without acknowledging it before static deployment';

    public function handle(StaticPublicationTransaction $transaction): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 20],
        ]);
        if (! is_int($limit)) {
            $this->error('Limit must be 1 through 20.');

            return self::INVALID;
        }
        try {
            $this->line(json_encode($transaction->prepare($limit), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error(substr($error->getMessage(), 0, 300));

            return self::FAILURE;
        }
    }
}
