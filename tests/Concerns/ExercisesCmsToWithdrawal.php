<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests\Concerns;

use CodeWorksLabs\DiscussionBridgeStatamic\Cms\CmsException;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\PrivateState;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\ToClosures;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\ToSourcePreparation;
use CodeWorksLabs\DiscussionBridgeStatamic\Delivery\DeliveryWorker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Statamic\Events\EntryDeleting;
use Statamic\Events\EntrySaving;
use Statamic\Facades\Entry;

/** Real native profiles; HTTP peer is an explicitly controlled typed fixture. */
trait ExercisesCmsToWithdrawal
{
    private function acceptedToEntry()
    {
        $entry = $this->toEntry();
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['delivered']);
        return $entry;
    }

    private function toState(): array
    {
        return app(PrivateState::class)->locked(fn ($store) => $store->read());
    }

    public static function nativeClosureCases(): array
    {
        return [['unpublish'], ['delete'], ['opt-out'], ['quiet-unpublish'], ['quiet-delete'], ['repository-unpublish'], ['repository-delete']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nativeClosureCases')]
    public function test_current_to_native_closure_withdraws_same_accepted_binding_without_content_preparation(string $operation): void
    {
        $entry = $this->acceptedToEntry();
        $before = DB::table('discussionbridge_deliveries')->sole();
        $accepted = $this->toState()['to'][$before->external_id]['accepted'];
        // Binding-only05 does not manufacture approval of the pending HTML rule.
        $this->app->instance(ToSourcePreparation::class, new ToSourcePreparation(app(\CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration::class)));
        // ToClosures was resolved during initial native save; refresh its actual
        // dependency as a new application would, keeping the same private store.
        $this->app->forgetInstance(ToClosures::class);
        match ($operation) {
            'unpublish' => $entry->published(false)->save(),
            'delete' => $entry->delete(),
            'opt-out' => $entry->set('discussionbridge_publish', false)->save(),
            'quiet-unpublish' => $entry->published(false)->saveQuietly(),
            'quiet-delete' => $entry->deleteQuietly(),
            'repository-unpublish' => Entry::getFacadeRoot()->save($entry->published(false)),
            'repository-delete' => Entry::getFacadeRoot()->delete($entry),
        };
        $captured = $this->toState();
        $closure = $captured['to'][$before->external_id]['closure'];
        $this->assertSame('confirmed', $closure['stage']);
        $this->assertSame($accepted, $closure['accepted_before']);
        $this->assertSame($before->canonical_url, $closure['native_before']['canonical_url']);
        $this->assertSame($closure['input'], $captured['mutations'][$closure['mutation_key']]['body']);
        $this->assertCount(0, $this->toWithdrawRequests, 'Native operation must not perform HTTP.');
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['withdrawn']);
        $after = DB::table('discussionbridge_deliveries')->sole();
        $this->assertSame('withdrawn', $after->status);
        foreach (['entry_id', 'external_id', 'canonical_url', 'resource_id', 'topic_id', 'topic_url', 'cms_source_revision'] as $field) {
            $this->assertSame($before->$field, $after->$field, $field);
        }
        $body = json_decode($this->toWithdrawRequests[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($before->cms_source_revision, $body['expected_source_revision']);
        $this->assertSame(str_contains($operation, 'delete') ? 'source_deleted' : ($operation === 'opt-out' ? 'source_ineligible' : 'source_unpublished'), $body['reason']);
        $this->assertSame('withdrawn', $this->toReceiverRecord['state']);
        $this->assertSame($accepted, $this->toState()['to'][$before->external_id]['accepted']);
        $this->assertSame(0, app(DeliveryWorker::class)->work(1)['processed']);
        $this->assertCount(1, $this->toRequests, 'No02 replay/resume or topic replacement.');
    }

    public static function cancelledClosureCases(): array
    {
        return [['save', false], ['save', true], ['delete', false], ['delete', true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cancelledClosureCases')]
    public function test_current_to_cancelled_or_throwing_native_close_restores_exact_queue_without_permanent_hold(string $operation, bool $throws): void
    {
        $entry = $this->acceptedToEntry();
        DB::table('discussionbridge_deliveries')->update(['status' => 'failed', 'attempts' => 4,
            'last_error' => 'transport_timeout', 'next_attempt_at' => now()->addHour()]);
        $before = (array) DB::table('discussionbridge_deliveries')->sole();
        $event = $operation === 'save' ? EntrySaving::class : EntryDeleting::class;
        Event::listen($event, function () use ($throws) {
            if ($throws) {
                throw new \RuntimeException('Controlled native cancellation failure.');
            }
            return false;
        });
        try {
            $result = $operation === 'save' ? $entry->published(false)->save() : $entry->delete();
            $this->assertFalse($result);
            $this->assertFalse($throws);
        } catch (\RuntimeException $error) {
            $this->assertTrue($throws);
            $this->assertSame('Controlled native cancellation failure.', $error->getMessage());
        }
        $after = (array) DB::table('discussionbridge_deliveries')->sole();
        foreach (['status', 'attempts', 'last_error', 'next_attempt_at', 'desired_revision', 'cms_source_revision'] as $field) {
            $this->assertSame($before[$field], $after[$field], $field);
        }
        $slot = $this->toState()['to'][$before['external_id']];
        $this->assertArrayNotHasKey('closure', $slot);
        $this->assertCount(1, $slot['closure_history']);
        $this->assertSame('abandoned_unchanged', array_values($slot['closure_history'])[0]['stage']);
        $this->assertTrue(app(ToSourcePreparation::class)->bindingSnapshot((object) $before)['published']);
        $this->assertCount(0, $this->toWithdrawRequests);
        // Lock is released even on cancellation/throw, with no manual action.
        $this->assertSame(0, app(DeliveryWorker::class)->work(1)['processed']);
    }

    public function test_current_to_withdrawal_lost_response_restarts_exact05_without_second_write(): void
    {
        $entry = $this->acceptedToEntry();
        $entry->published(false)->save();
        $this->toLoseWithdrawResponse = true;
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['failed']);
        $this->assertSame(2, $this->toReceiverWrites);
        $this->app->forgetInstance(PrivateState::class);
        $this->app->forgetInstance(ToClosures::class);
        $this->travel(2)->minutes();
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['withdrawn']);
        $this->assertSame($this->toWithdrawRequests[0], $this->toWithdrawRequests[1]);
        $this->assertSame(2, $this->toReceiverWrites);
        $this->assertSame('withdrawn', DB::table('discussionbridge_deliveries')->sole()->status);
    }

    public static function postPersistenceThrowCases(): array
    {
        return [['save'], ['delete']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('postPersistenceThrowCases')]
    public function test_current_to_throwing_post_event_uses_actual_persisted_close_instead_of_return_value(string $operation): void
    {
        $entry = $this->acceptedToEntry();
        Event::listen($operation === 'save' ? \Statamic\Events\EntrySaved::class : \Statamic\Events\EntryDeleted::class,
            fn () => throw new \RuntimeException('Controlled post-persistence native failure.'));
        try {
            $operation === 'save' ? $entry->published(false)->save() : $entry->delete();
            $this->fail('Post-event failure did not propagate.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Controlled post-persistence native failure.', $error->getMessage());
        }
        $row = DB::table('discussionbridge_deliveries')->sole();
        $closure = $this->toState()['to'][$row->external_id]['closure'];
        $this->assertSame('threw', $closure['operation_completed']);
        $this->assertSame('confirmed', $closure['stage']);
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['withdrawn']);
    }

    public function test_current_to_cached_unwrapped_entry_close_is_rejected_before_capture_and_does_not_hold_source(): void
    {
        $entry = $this->acceptedToEntry();
        $row = DB::table('discussionbridge_deliveries')->sole();
        $unwrapped = $entry instanceof \Statamic\Eloquent\Entries\Entry
            ? \Statamic\Eloquent\Entries\Entry::fromModel($entry->model()->fresh())
            : (new \Statamic\Entries\Entry)->id($entry->id())->collection($entry->collection())->locale($entry->locale())
                ->slug($entry->slug())->published(true)->data($entry->data()->all());
        try {
            $unwrapped->published(false)->save();
            $this->fail('Unsupported outer entry operation was allowed.');
        } catch (CmsException $error) {
            $this->assertSame('native_source_entry_operation_unsupported', $error->failureCode);
        }
        $this->assertArrayNotHasKey('closure', $this->toState()['to'][$row->external_id]);
        $this->assertTrue(app(ToSourcePreparation::class)->bindingSnapshot($row)['published']);
        $this->assertTrue($entry->published(false)->save());
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['withdrawn']);
    }

    public function test_current_to_closed_acceptedA_keeps_uncertain02B_and_withdraws_only_currentA(): void
    {
        $entry = $this->acceptedToEntry();
        $row = DB::table('discussionbridge_deliveries')->sole();
        // Persist a real fresh-edition02 intent, fail BEFORE receiver acceptance.
        $entry->set('content', "## Native source\n\nChanged complete é source ending.")->set('updated_at', $entry->get('updated_at') + 60)->save();
        // Use the actual journal builder; fail before the peer accepts02.
        $original = $this->toReceiverRecord;
        $this->toRejectResolveBeforeAccept = true;
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['failed']);
        $pending = $this->toState()['to'][$row->external_id]['pending'];
        $this->toReceiverRecord = $original;
        $entry->published(false)->save();
        // The new closure has its own due queue entry, preserving the original02.
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['withdrawn']);
        $this->assertSame($pending, $this->toState()['to'][$row->external_id]['pending']);
        $this->assertSame($row->cms_source_revision, json_decode($this->toWithdrawRequests[0], true)['expected_source_revision']);
        $this->assertCount(2, $this->toRequests);
    }

    public function test_current_to_concurrent_receiver_edition_conflict_keeps_capture_and_accepted_history(): void
    {
        $entry = $this->acceptedToEntry();
        $row = DB::table('discussionbridge_deliveries')->sole();
        $entry->published(false)->save();
        $before = $this->toState()['to'][$row->external_id];
        $this->toRaceWithdraw = true;
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['reconciliation_required']);
        $after = $this->toState()['to'][$row->external_id];
        $this->assertSame($before['accepted'], $after['accepted']);
        $this->assertSame($before['accepted_history'], $after['accepted_history']);
        $this->assertSame($before['closure']['input'], $after['closure']['input']);
        $this->assertSame('revision_conflict', DB::table('discussionbridge_deliveries')->sole()->last_error);
        $this->assertSame('active', $this->toReceiverRecord['state']);
        $this->assertCount(1, $this->toRequests);
    }

    public function test_current_to_unknown02B_acceptance_is_not_inferred_from04_metadata_after_native_close(): void
    {
        $entry = $this->acceptedToEntry();
        $row = DB::table('discussionbridge_deliveries')->sole();
        $entry->set('content', "## Native source\n\nChanged complete é source ending.")->set('updated_at', $entry->get('updated_at') + 60)->save();
        $this->toLoseResponse = true;
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['failed']);
        $pending = $this->toState()['to'][$row->external_id]['pending'];
        $entry->published(false)->save();
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['reconciliation_required']);
        $slot = $this->toState()['to'][$row->external_id];
        $this->assertSame($pending, $slot['pending']);
        $this->assertSame($row->cms_source_revision, $slot['accepted']['source_revision']);
        $this->assertCount(0, $this->toWithdrawRequests);
        $this->assertCount(2, $this->toRequests, 'No automatic exact02 replay after native closed.');
    }

    public function test_current_to_shared_writer_is_held_across_actual_native_closure_events(): void
    {
        $entry = $this->acceptedToEntry();
        $observed = false;
        Event::listen(EntrySaving::class, function () use (&$observed) {
            try {
                app(DeliveryWorker::class)->work(1);
                $this->fail('Native closure released the source writer before persistence.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('Nested CMS writer lock', $error->getMessage());
                $observed = true;
            }
        });
        $entry->published(false)->save();
        $this->assertTrue($observed);
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['withdrawn']);
    }

    public static function closureRecoveryCases(): array
    {
        return [['closed'], ['confirmed-sql'], ['completed-cancel'], ['unknown-cancel'], ['abandoned-sql']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('closureRecoveryCases')]
    public function test_current_to_restart_reconciles_native_outcome_and_requires_real_completion_before_clearing(string $case): void
    {
        $entry = $this->acceptedToEntry();
        $row = DB::table('discussionbridge_deliveries')->sole();
        $prepared = null;
        Event::listen(EntrySaving::class, function () use (&$prepared, $case) {
            $prepared = app(PrivateState::class)->read(); // Actual already-held operation writer.
            return in_array($case, ['closed', 'confirmed-sql'], true) ? null : false;
        });
        $entry->published(false)->save();
        $this->assertNotNull($prepared);
        $finished = $this->toState();
        app(PrivateState::class)->locked(function ($store) use ($case, $row, $prepared, $finished) {
            if (in_array($case, ['abandoned-sql', 'confirmed-sql'], true)) {
                $store->save($finished); // Durable finally result, SQL update lost.
            } else {
                if ($case === 'completed-cancel') {
                    $prepared['to'][$row->external_id]['closure']['operation_completed'] = 'cancelled';
                }
                $store->save($prepared); // Inject exact pre-finally crash snapshot.
            }
            DB::table('discussionbridge_deliveries')->where('id', $row->id)->update(['status' => 'withdraw_capture', 'next_attempt_at' => null]);
        });
        $this->app->forgetInstance(PrivateState::class);
        $this->app->forgetInstance(ToClosures::class);
        $summary = app(DeliveryWorker::class)->work(1);
        $slot = $this->toState()['to'][$row->external_id];
        if (in_array($case, ['closed', 'confirmed-sql'], true)) {
            $this->assertSame(1, $summary['withdrawn']);
            $this->assertSame('withdrawn', $slot['closure']['stage']);
        } elseif ($case === 'unknown-cancel') {
            $this->assertSame(0, $summary['processed']);
            $this->assertSame('prepared', $slot['closure']['stage']);
            $this->assertSame('withdraw_capture', DB::table('discussionbridge_deliveries')->sole()->status);
        } else {
            $this->assertSame(0, $summary['processed']);
            $this->assertArrayNotHasKey('closure', $slot);
            $this->assertSame($row->status, DB::table('discussionbridge_deliveries')->sole()->status);
            $this->assertSame($row->attempts, DB::table('discussionbridge_deliveries')->sole()->attempts);
        }
    }
}
