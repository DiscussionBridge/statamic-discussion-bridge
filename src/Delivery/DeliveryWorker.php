<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Delivery;

use CodeWorksLabs\DiscussionBridgeStatamic\Cms\CmsException;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\PrivateState;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\ToDelivery;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\ToClosures;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\UncertainMutation;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\NativePreparationLimitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DeliveryWorker
{
    public function work(int $limit): array
    {
        return app(PrivateState::class)->locked(fn () => $this->workBatch($limit));
    }

    private function workBatch(int $limit): array
    {
        $limit = max(1, min($limit, 100));
        $store = app(PrivateState::class);
        $state = $store->read();
        app(\CodeWorksLabs\DiscussionBridgeStatamic\Cms\ToSourceMoves::class)->recover($state);
        app(ToClosures::class)->recover($state);
        $this->recoverStaleClaims();
        // Existing bounded native To transport retries; these are not receiver
        // From Work attempt cycles and never reset a central attempt/count.
        $ids = DB::table('discussionbridge_deliveries')->whereIn('status', ['pending', 'failed', 'withdraw_pending'])
            ->where('attempts', '<', 10)
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')->limit($limit)->pluck('id');
        $summary = ['processed' => 0, 'delivered' => 0, 'withdrawn' => 0, 'failed' => 0, 'reconciliation_required' => 0, 'cancelled' => 0];
        foreach ($ids as $id) {
            $status = $this->process((int) $id);
            if ($status !== null) {
                $summary['processed']++;
                $summary[$status]++;
            }
        }
        return $summary;
    }

    private function process(int $id): ?string
    {
        $token = (string) Str::uuid();
        $claimed = DB::table('discussionbridge_deliveries')->where('id', $id)->whereIn('status', ['pending', 'failed', 'withdraw_pending'])
            ->update(['status' => 'processing', 'lock_token' => $token, 'locked_at' => now(), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return null;
        }
        $row = DB::table('discussionbridge_deliveries')->where('id', $id)->where('lock_token', $token)->first();
        try {
            if (! $row) {
                throw new CmsException('native_restore_required');
            }
            $values = app(ToDelivery::class)->deliver($row);
            if ($values['outcome'] === 'withdrawn') {
                return $this->finish($id, $token, 'withdrawn', [...$values,
                    'attempts' => ((int) $row->attempts) + 1, 'last_error' => null, 'next_attempt_at' => null]);
            }
            $current = DB::table('discussionbridge_deliveries')->where('id', $id)->where('lock_token', $token)->first();
            if (! $current) {
                throw new CmsException('native_restore_required');
            }
            $newer = $current->desired_revision !== null && $current->desired_revision !== $values['cms_native_revision'];
            return $this->finish($id, $token, 'delivered', [...$values,
                'attempts' => ((int) $row->attempts) + 1, 'last_error' => null,
                ...($newer ? ['status' => 'pending', 'next_attempt_at' => now()]
                    : ['desired_revision' => $values['cms_native_revision'], 'next_attempt_at' => null])]);
        } catch (CmsException $error) {
            if ($error instanceof UncertainMutation || in_array($error->failureCode, ['transport_timeout', 'work_busy'], true)) {
                return $this->fail($id, $token, $error->failureCode);
            }
            $diagnostic = $error instanceof NativePreparationLimitation
                ? $error->failureCode.':'.$error->reason : $error->failureCode;
            return $this->finish($id, $token, 'reconciliation_required', ['last_error' => $diagnostic,
                'attempts' => ((int) ($row->attempts ?? 0)) + 1, 'next_attempt_at' => null]);
        } catch (Throwable $error) {
            if (app()->environment('testing')) {
                throw $error;
            }
            report($error);
            return $this->fail($id, $token, 'delivery_failed');
        }
    }

    private function fail(int $id, string $token, string $reason): string
    {
        $row = DB::table('discussionbridge_deliveries')->where('id', $id)->first();
        $attempts = min(10, ((int) ($row->attempts ?? 0)) + 1);
        $seconds = min(3600, 60 * (2 ** min($attempts - 1, 6)));
        return $this->finish($id, $token, 'failed', ['attempts' => $attempts,
            'last_error' => substr($reason, 0, 500), 'next_attempt_at' => now()->addSeconds($seconds)]);
    }

    private function finish(int $id, string $token, string $status, array $values): string
    {
        $changed = DB::table('discussionbridge_deliveries')->where('id', $id)->where('lock_token', $token)
            ->update(['status' => $status, ...$values, 'lock_token' => null, 'locked_at' => null, 'updated_at' => now()]);
        if ($changed !== 1) {
            throw new RuntimeException('Statamic delivery claim was lost before state persistence.');
        }
        return $status;
    }

    private function recoverStaleClaims(): void
    {
        DB::table('discussionbridge_deliveries')->where('status', 'processing')->where('locked_at', '<', now()->subMinutes(10))
            ->update(['status' => 'failed', 'lock_token' => null, 'locked_at' => null,
                'last_error' => 'stale_worker_claim', 'next_attempt_at' => now(), 'updated_at' => now()]);
    }
}
