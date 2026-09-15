<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Http\Controllers;

use CodeWorksLabs\DiscussionBridgeStatamic\Publication\PublicationSynchronizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Statamic\Http\Controllers\CP\CpController;
use Throwable;

class PublicationSyncController extends CpController
{
    public const LAST_RESULT_CACHE_KEY = 'discussionbridge:publication-sync:last-result';

    public function __invoke(PublicationSynchronizer $synchronizer): RedirectResponse
    {
        $lock = Cache::lock('discussionbridge:publication-sync', 300);
        if (! $lock->get()) {
            return back()->with('error', 'A DiscussionBridge publication synchronization is already running.');
        }

        try {
            $summary = $synchronizer->synchronize();
        } catch (Throwable $error) {
            Cache::put(self::LAST_RESULT_CACHE_KEY, [
                'completed_at' => now()->toIso8601String(),
                'succeeded' => false,
                'created' => 0,
                'updated' => 0,
                'unchanged' => 0,
                'skipped' => 0,
                'failed' => 1,
                'errors' => [substr($error->getMessage(), 0, 240)],
            ], now()->addDays(30));

            return back()->with('error', 'DiscussionBridge synchronization failed: '.substr($error->getMessage(), 0, 240));
        } finally {
            $lock->release();
        }

        Cache::put(self::LAST_RESULT_CACHE_KEY, array_merge($summary, [
            'completed_at' => now()->toIso8601String(),
            'succeeded' => $summary['failed'] === 0,
        ]), now()->addDays(30));

        $message = sprintf(
            'Synchronization complete: %d created, %d updated, %d already current, %d skipped, %d failed.',
            $summary['created'],
            $summary['updated'],
            $summary['unchanged'],
            $summary['skipped'],
            $summary['failed'],
        );

        if ($summary['failed'] > 0) {
            return back()->with('error', $message.' '.implode(' ', $summary['errors']));
        }

        return back()->with('success', $message);
    }
}
