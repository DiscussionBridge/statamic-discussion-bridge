<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Http\Controllers;

use CodeWorksLabs\DiscussionBridgeStatamic\Cms\DynamicWorker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Statamic\Http\Controllers\CP\CpController;

class PublicationSyncController extends CpController
{
    public const LAST_RESULT_CACHE_KEY = 'discussionbridge:publication-sync:last-result';

    public function __invoke(DynamicWorker $worker): RedirectResponse
    {
        $lock = Cache::lock('discussionbridge:publication-sync', 300);
        if (! $lock->get()) {
            return back()->with('error', 'A DiscussionBridge synchronization is already running.');
        }
        try {
            $summary = $worker->run();
            $success = $summary['attention'] === 0 && ($summary['local_issues'] ?? 0) === 0
                && ($summary['attempt_failures'] ?? 0) === 0;
            Cache::put(self::LAST_RESULT_CACHE_KEY, [...$summary, 'completed_at' => now()->toIso8601String(),
                'succeeded' => $success], now()->addDays(30));
            return back()->with($success ? 'success' : 'error', sprintf(
                'Synchronization: %d processed, %d publicly verified, %d receiver attention, %d waiting to retry, %d failed attempts, %d local content limitations.',
                $summary['processed'], $summary['verified'], $summary['attention'], $summary['retry_wait'] ?? 0,
                $summary['attempt_failures'] ?? 0, $summary['local_issues'] ?? 0));
        } catch (\Throwable) {
            Cache::put(self::LAST_RESULT_CACHE_KEY, ['completed_at' => now()->toIso8601String(), 'succeeded' => false,
                'processed' => 0, 'verified' => 0, 'attention' => 0, 'local_issues' => 0, 'attempt_failures' => 1], now()->addDays(30));
            return back()->with('error', 'DiscussionBridge current authority or native integration requires attention.');
        } finally {
            $lock->release();
        }
    }
}
