<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Console;

use CodeWorksLabs\DiscussionBridgeStatamic\Delivery\DeliveryWorker;
use Illuminate\Console\Command;

class WorkDeliveries extends Command
{
    protected $signature = 'discussionbridge:work {--limit=25 : Maximum deliveries to process}';
    protected $description = 'Process a bounded batch of pending DiscussionBridge deliveries';

    public function handle(DeliveryWorker $worker): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($limit === false) {
            $this->error('Limit must be an integer from 1 through 100.');

            return self::INVALID;
        }

        $result = $worker->work($limit);
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return $result['failed'] > 0 || $result['reconciliation_required'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
