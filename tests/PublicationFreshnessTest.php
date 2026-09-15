<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Publication\PublicationFreshness;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Statamic\Contracts\Entries\Entry;
use Statamic\StaticCaching\Invalidator;

class PublicationFreshnessTest extends TestCase
{
    public function test_it_invalidates_the_public_entry_and_bounded_record_cache(): void
    {
        $entry = Mockery::mock(Entry::class);
        $invalidator = Mockery::mock(Invalidator::class);
        $invalidator->shouldReceive('refresh')->once()->with($entry);

        $freshness = new PublicationFreshness(app(\CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration::class), $invalidator);
        $resourceId = '11111111-1111-4111-8111-111111111111';
        $key = $freshness->recordCacheKey($resourceId);
        Cache::put($key, ['stale' => true], 60);

        $freshness->refresh($entry, $resourceId);

        $this->assertFalse(Cache::has($key));
    }
}
