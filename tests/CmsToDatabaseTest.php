<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

class CmsToDatabaseTest extends NativeDatabaseTestCase
{
    use \CodeWorksLabs\DiscussionBridgeStatamic\Tests\Concerns\ExercisesCmsToDelivery;
    use \CodeWorksLabs\DiscussionBridgeStatamic\Tests\Concerns\ManagesPresentationFiles;

    public static function modelCancellationCases(): array
    {
        return [['saving'], ['deleting']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modelCancellationCases')]
    public function test_current_to_native_eloquent_model_cancellation_is_not_inferred_success_from_entry_return(string $event): void
    {
        $entry = $this->acceptedToEntry();
        $row = \Illuminate\Support\Facades\DB::table('discussionbridge_deliveries')->sole();
        $class = get_class($entry->model());
        $class::$event(fn () => false);
        // Official repository ignores the cancelled model return; actual persisted
        // readback must override the outer Entry true/post-event indication.
        $this->assertTrue($event === 'saving' ? $entry->published(false)->save() : $entry->delete());
        $this->assertArrayNotHasKey('closure', $this->toState()['to'][$row->external_id]);
        $this->assertTrue(app(\CodeWorksLabs\DiscussionBridgeStatamic\Cms\ToSourcePreparation::class)->bindingSnapshot($row)['published']);
        $this->assertSame($row->status, \Illuminate\Support\Facades\DB::table('discussionbridge_deliveries')->sole()->status);
        $this->assertCount(0, $this->toWithdrawRequests);
    }

    public function test_current_to_outer_native_database_transaction_does_not_claim_uncommitted_close_completion(): void
    {
        $entry = $this->acceptedToEntry();
        $connection = $entry->model()->getConnection();
        $connection->beginTransaction();
        try {
            $entry->published(false)->save();
            $this->fail('An outer native transaction was treated as committed.');
        } catch (\CodeWorksLabs\DiscussionBridgeStatamic\Cms\CmsException $error) {
            $this->assertSame('native_source_outer_transaction_unsupported', $error->failureCode);
        } finally {
            $connection->rollBack();
        }
        $row = \Illuminate\Support\Facades\DB::table('discussionbridge_deliveries')->sole();
        $this->assertArrayNotHasKey('closure', $this->toState()['to'][$row->external_id]);
        $this->assertCount(0, $this->toWithdrawRequests);
    }
}
