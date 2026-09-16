<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Delivery\DeliveryEnqueuer;
use Illuminate\Support\LazyCollection;
use Mockery;
use Statamic\Facades\Entry;

class ReconcileDeliveriesTest extends TestCase
{
    public function test_it_traverses_entries_in_bounded_batches_and_preserves_summary(): void
    {
        $entries = array_map(fn ($index) => (object) ['index' => $index], range(1, 205));
        $query = Mockery::mock();
        $query->shouldReceive('whereIn')->once()->with('collection', ['pages'])->andReturnSelf();
        $query->shouldReceive('lazy')->once()->with(100)->andReturn(LazyCollection::make($entries));
        Entry::shouldReceive('query')->once()->andReturn($query);

        $enqueuer = Mockery::mock(DeliveryEnqueuer::class);
        $enqueuer->shouldReceive('enqueue')->times(205)->andReturnUsing(fn ($entry) => $entry->index === 203);
        $this->app->instance(DeliveryEnqueuer::class, $enqueuer);

        $this->artisan('discussionbridge:reconcile')
            ->expectsOutput('{"scanned":205,"enqueued":1,"errors":0}')
            ->assertSuccessful();
    }
}
