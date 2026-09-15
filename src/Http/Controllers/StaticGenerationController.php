<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Http\Controllers;

use CodeWorksLabs\DiscussionBridgeStatamic\StaticSite\StaticSiteGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Statamic\Http\Controllers\CP\CpController;
use Throwable;

class StaticGenerationController extends CpController
{
    public const LAST_RESULT_CACHE_KEY = 'discussionbridge:static-generation:last-result';

    public function __invoke(StaticSiteGenerator $generator): RedirectResponse
    {
        if (! $generator->available()) {
            return back()->with('error', 'Static site generation is not active for this Statamic profile.');
        }

        $lock = Cache::lock('discussionbridge:static-generation', 900);
        if (! $lock->get()) {
            return back()->with('error', 'DiscussionBridge static generation is already running.');
        }

        try {
            $summary = $generator->generate();
        } catch (Throwable $error) {
            Cache::put(self::LAST_RESULT_CACHE_KEY, [
                'completed_at' => now()->toIso8601String(),
                'succeeded' => false,
                'prepared' => false,
                'generated' => false,
                'error' => substr($error->getMessage(), 0, 240),
            ], now()->addDays(30));

            return back()->with('error', 'Static generation failed: '.substr($error->getMessage(), 0, 240));
        } finally {
            $lock->release();
        }

        Cache::put(self::LAST_RESULT_CACHE_KEY, array_merge($summary, [
            'completed_at' => now()->toIso8601String(),
            'succeeded' => true,
        ]), now()->addDays(30));

        return back()->with('success', 'Static site regenerated successfully.');
    }
}
