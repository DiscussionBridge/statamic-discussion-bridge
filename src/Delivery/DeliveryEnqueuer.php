<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Delivery;

use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\EntryIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeliveryEnqueuer
{
    public function __construct(private readonly Configuration $configuration)
    {
    }

    public function enqueue(object $entry): bool
    {
        $collection = (string) $entry->collectionHandle();
        if (! $this->configuration->enabled() || ! $this->configuration->collectionAllowed($collection)) {
            return false;
        }
        if ($entry->published() !== true || $entry->status() !== 'published' || $entry->get('discussionbridge_publish') !== true) {
            return false;
        }
        if ($entry instanceof \Statamic\Entries\Entry && $entry->get('discussionbridge_native_publication') === true) {
            return false;
        }
        if (app(\CodeWorksLabs\DiscussionBridgeStatamic\Cms\ToClosures::class)->blocksEnqueue($entry)) {
            return false;
        }

        $canonicalUrl = $entry->absoluteUrl();
        $site = (string) $entry->site()->handle();
        if (! is_string($canonicalUrl) || strlen($canonicalUrl) > 2048 || ! str_starts_with($canonicalUrl, $this->configuration->siteOrigin().'/')) {
            return false;
        }
        $publication = SourcePublication::fromEntry($entry, $this->configuration);
        $revision = $publication['record']['source_publication']['revision'];
        return DB::transaction(function () use ($entry, $site, $collection, $canonicalUrl, $revision): bool {
            $existing = DB::table('discussionbridge_deliveries')->where('site_handle', $site)->where('collection_handle', $collection)->where('entry_id', (string) $entry->id())->lockForUpdate()->first();
            if ($existing) {
                if (str_starts_with($existing->status, 'withdraw_') || $existing->status === 'withdrawn') {
                    return false; // Actual closure is never implicit To reactivation.
                }
                if ($existing->canonical_url !== $canonicalUrl) {
                    DB::table('discussionbridge_deliveries')->where('id', $existing->id)->update([
                        'last_error' => 'canonical_url_changed',
                        ...($existing->status === 'processing' ? [] : ['status' => 'reconciliation_required']),
                        'updated_at' => now(),
                    ]);
                    return false;
                }
                $changedRejectedSource = $existing->status === 'reconciliation_required'
                    && in_array($existing->last_error, ['content_preservation_unavailable', 'invalid_field'], true)
                    && app(\CodeWorksLabs\DiscussionBridgeStatamic\Cms\ToClosures::class)->rejectedSourceChanged($entry, $revision);
                if ($existing->desired_revision === $revision && ! $changedRejectedSource) {
                    return false;
                }
                $values = ['desired_revision' => $revision, 'updated_at' => now()];
                $acceptedRevision = $existing->cms_native_revision;
                if ($existing->status !== 'processing' && ($existing->status !== 'reconciliation_required' || $changedRejectedSource)) {
                    $values += ['status' => ! $changedRejectedSource && $acceptedRevision === $revision ? 'delivered' : 'pending', 'attempts' => 0, 'next_attempt_at' => now(), 'last_error' => null];
                }
                DB::table('discussionbridge_deliveries')->where('id', $existing->id)->update($values);

                return $existing->status !== 'processing' && ($existing->status !== 'reconciliation_required' || $changedRejectedSource)
                    && ($changedRejectedSource || $acceptedRevision !== $revision);
            }

            return DB::table('discussionbridge_deliveries')->insertOrIgnore([
                'entry_id' => (string) $entry->id(),
                'collection_handle' => $collection,
                'site_handle' => $site,
                'external_id' => EntryIdentity::externalId($this->configuration->siteOrigin(), $site, $collection, (string) $entry->id()),
                'canonical_url' => $canonicalUrl,
                'desired_revision' => $revision,
                'status' => 'pending',
                'correlation_id' => (string) Str::uuid(),
                'attempts' => 0,
                'next_attempt_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]) === 1;
        });
    }
}
