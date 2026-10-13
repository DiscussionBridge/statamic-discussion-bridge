<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Delivery\DeliveryEnqueuer;
use Illuminate\Support\LazyCollection;
use Mockery;
use RuntimeException;
use Statamic\Facades\Entry;

class ReconcileDeliveriesTest extends TestCase
{
    public function test_reconciliation_streams_a_bounded_native_query_once_and_counts_errors(): void
    {
        $yielded = 0;
        $entries = LazyCollection::make(function () use (&$yielded) {
            for ($id = 0; $id < 70; $id++) {
                $yielded++;
                yield (object) ['id' => $id];
            }
        });
        $query = Mockery::mock();
        $query->shouldReceive('whereIn')->once()->with('collection', ['pages'])->andReturnSelf();
        $query->shouldReceive('orderBy')->once()->with('id')->andReturnSelf();
        $query->shouldReceive('lazy')->once()->with(32)->andReturn($entries);
        Entry::shouldReceive('query')->once()->andReturn($query);
        $enqueuer = Mockery::mock(DeliveryEnqueuer::class);
        $enqueuer->shouldReceive('enqueue')->times(70)->andReturnUsing(function ($entry) use (&$yielded) {
            $this->assertSame($entry->id + 1, $yielded, 'Entries must be processed before later entries are consumed.');
            if ($entry->id === 35) {
                throw new RuntimeException('Synthetic invalid source entry');
            }
            return $entry->id % 2 === 0;
        });
        $this->app->instance(DeliveryEnqueuer::class, $enqueuer);
        $this->artisan('discussionbridge:reconcile')
            ->expectsOutput('{"scanned":70,"enqueued":35,"errors":1}')
            ->assertFailed();
        $this->assertSame(70, $yielded, 'Reporting must not re-enumerate the source.');
    }
}
