<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Console;

use CodeWorksLabs\DiscussionBridgeStatamic\Delivery\DeliveryEnqueuer;
use Illuminate\Console\Command;
use Statamic\Facades\Entry;
use Throwable;

class ReconcileDeliveries extends Command
{
    protected $signature = 'discussionbridge:reconcile';
    protected $description = 'Enqueue eligible published entries that have no DiscussionBridge delivery state';

    public function handle(DeliveryEnqueuer $enqueuer): int
    {
        $created = 0;
        $errors = 0;
        $entries = Entry::query()->whereIn('collection', config('discussionbridge.collections', []))->get();
        foreach ($entries as $entry) {
            try {
                $created += $enqueuer->enqueue($entry) ? 1 : 0;
            } catch (Throwable $error) {
                report($error);
                $errors++;
            }
        }

        $this->line(json_encode(['scanned' => $entries->count(), 'enqueued' => $created, 'errors' => $errors], JSON_THROW_ON_ERROR));

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
