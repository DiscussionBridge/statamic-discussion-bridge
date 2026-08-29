<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RetryDelivery extends Command
{
    protected $signature = 'discussionbridge:retry {entry : Exact Statamic entry ID} {--delivered : Explicitly retry an already delivered identity}';
    protected $description = 'Authorize a retry for one exact delivery identity';

    public function handle(): int
    {
        $entryId = (string) $this->argument('entry');
        if ($entryId === '' || strlen($entryId) > 255) {
            $this->error('Entry ID is invalid.');

            return self::INVALID;
        }

        $states = ['failed', 'reconciliation_required', 'cancelled'];
        if ($this->option('delivered')) {
            $states[] = 'delivered';
        }

        $updated = DB::table('discussionbridge_deliveries')
            ->where('entry_id', $entryId)
            ->whereIn('status', $states)
            ->update([
                'status' => 'pending',
                'last_error' => null,
                'next_attempt_at' => now(),
                'lock_token' => null,
                'locked_at' => null,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            $this->error('No retryable delivery matched that exact entry ID.');

            return self::FAILURE;
        }

        $this->info('DiscussionBridge delivery queued for explicit retry.');

        return self::SUCCESS;
    }
}
