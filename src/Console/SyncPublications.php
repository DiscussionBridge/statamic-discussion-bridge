<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Console;

use CodeWorksLabs\DiscussionBridgeStatamic\Cms\DynamicWorker;
use Illuminate\Console\Command;

class SyncPublications extends Command
{
    protected $signature = 'discussionbridge:sync-publications';
    protected $description = 'Apply authorized native Statamic publications and verify their actual public output';

    public function handle(DynamicWorker $worker): int
    {
        try {
            $summary = $worker->run();
            $this->line(json_encode($summary, JSON_THROW_ON_ERROR));
            return $summary['attention'] === 0 && ($summary['local_issues'] ?? 0) === 0
                && ($summary['attempt_failures'] ?? 0) === 0 ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $error) {
            $this->error('DiscussionBridge current authority, typed transport or native integration requires attention.');
            return self::FAILURE;
        }
    }
}
