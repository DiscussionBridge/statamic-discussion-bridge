<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Console;

use CodeWorksLabs\DiscussionBridgeStatamic\Delivery\DeliveryWorker;
use CodeWorksLabs\DiscussionBridgeStatamic\Publication\StaticPublicationTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class PrepareStaticBuild extends Command
{
    protected $signature = 'discussionbridge:ssg-prepare
        {--limit=100 : Maximum deliveries to process per pass}
        {--passes=10 : Maximum delivery passes}
        {--bounded-publication-work : Treat an absent prepared transaction as an empty bounded publication queue}';
    protected $description = 'Reconcile and deliver DiscussionBridge records before generating a static Statamic site';

    public function handle(DeliveryWorker $worker, StaticPublicationTransaction $transaction): int
    {
        $limit = $this->boundedInteger('limit', 1, 100);
        $passes = $this->boundedInteger('passes', 1, 25);
        if ($limit === null || $passes === null) {
            $this->error('Limit must be 1 through 100 and passes must be 1 through 25.');

            return self::INVALID;
        }

        if ($this->call('discussionbridge:reconcile') !== self::SUCCESS) {
            $this->error('DiscussionBridge reconciliation failed. Static generation is blocked.');

            return self::FAILURE;
        }

        $processed = 0;
        for ($pass = 0; $pass < $passes; $pass++) {
            $result = $worker->work($limit);
            $processed += $result['processed'];
            if ($result['processed'] === 0) {
                break;
            }
            if ($result['failed'] > 0 || $result['reconciliation_required'] > 0) {
                break;
            }
        }

        $states = DB::table('discussionbridge_deliveries')
            ->select('status', DB::raw('COUNT(*) AS total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();
        $blocking = array_sum(array_intersect_key($states, array_flip([
            'pending',
            'processing',
            'failed',
            'reconciliation_required',
        ])));

        $this->line(json_encode([
            'processed' => $processed,
            'states' => $states,
            'ready' => $blocking === 0,
        ], JSON_THROW_ON_ERROR));

        if ($blocking > 0) {
            $this->error('Unresolved DiscussionBridge delivery state blocks static generation.');

            return self::FAILURE;
        }

        try {
            $preparedTransaction = $transaction->preparedForStaticBuild();
        } catch (Throwable $error) {
            $this->error(substr($error->getMessage(), 0, 300));

            return self::FAILURE;
        }

        if ($preparedTransaction) {
            $this->line(json_encode([
                'publication_sync' => 'bounded_transaction',
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if ((bool) $this->option('bounded-publication-work')) {
            $this->line(json_encode([
                'publication_sync' => 'bounded_queue_empty',
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if ($this->call('discussionbridge:sync-publications') !== self::SUCCESS) {
            $this->error('DiscussionBridge From Discourse publication sync failed. Static generation is blocked.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function boundedInteger(string $name, int $minimum, int $maximum): ?int
    {
        $value = filter_var($this->option($name), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => $minimum, 'max_range' => $maximum],
        ]);

        return is_int($value) ? $value : null;
    }
}
