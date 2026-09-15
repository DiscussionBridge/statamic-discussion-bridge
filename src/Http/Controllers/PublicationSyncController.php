<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Http\Controllers;

use CodeWorksLabs\DiscussionBridgeStatamic\Publication\PublicationSynchronizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Statamic\Http\Controllers\CP\CpController;
use Throwable;

class PublicationSyncController extends CpController
{
    public function __invoke(PublicationSynchronizer $synchronizer): RedirectResponse
    {
        $lock = Cache::lock('discussionbridge:publication-sync', 300);
        if (! $lock->get()) {
            return back()->with('error', 'A DiscussionBridge publication synchronization is already running.');
        }

        try {
            $summary = $synchronizer->synchronize();
        } catch (Throwable $error) {
            return back()->with('error', 'DiscussionBridge synchronization failed: '.substr($error->getMessage(), 0, 240));
        } finally {
            $lock->release();
        }

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
