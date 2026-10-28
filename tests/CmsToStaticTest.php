<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

class CmsToStaticTest extends NativeStaticTestCase
{
    use \CodeWorksLabs\DiscussionBridgeStatamic\Tests\Concerns\ExercisesCmsToDelivery;

    protected function toProfile(): string
    {
        return 'statamic_ssg';
    }

    public function test_current_to_source_ssg_lost_deployment_receipt_uses_actual_owner_reconciliation_without_second_deploy(): void
    {
        $entry = $this->sourceMoveEntry('lost-deployment-receipt');
        $row = \Illuminate\Support\Facades\DB::table('discussionbridge_deliveries')->sole();
        $entry->slug('cms-native-static-reconcile')->save();
        $this->assertSame(1, app(\CodeWorksLabs\DiscussionBridgeStatamic\Delivery\DeliveryWorker::class)->work(1)['delivered']);
        $state = $this->toState();
        $transaction = array_values($state['source_static'])[0];
        $this->assertSame('to_source_move', $transaction['owner']);
        $this->assertSame('captured', $transaction['build']['state']);
        $this->assertSame('reconciled', $transaction['commands']['deploy']['state']);
        $this->assertSame(8, $transaction['commands']['deploy']['exit_code']);
        $this->assertCount(2, $transaction['commands']);
        $reconcile = array_values(array_filter($transaction['commands'], fn ($command) => $command['operation'] === 'reconcile'))[0];
        $this->assertSame($transaction['commands']['deploy']['command_id'], $reconcile['reconciles']);
        $this->assertSame($transaction['artifact']['sha256'], $transaction['deployment']['artifact_sha256']);
        $this->assertArrayNotHasKey('static', $state);
        $this->assertArrayNotHasKey('lease', $transaction);
        $this->assertCount(1, $this->sourceMoveRequests['finalize']);
        $this->assertSame($row->entry_id, \Statamic\Facades\Entry::find($row->entry_id)->id());
    }
}
