<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

use Carbon\CarbonImmutable;
use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\HtmlSanitizer;
use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\PresentationChrome;
use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\StandalonePresenter;
use CodeWorksLabs\DiscussionBridgeStatamic\Publication\PublicationFreshness;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\PresentationAssets;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;
use stdClass;

/** Native From-only CMS writer; creates or mutates only its owned mapped objects. */
class NativeWriter
{
    public function __construct(private readonly Configuration $configuration, private readonly CmsClient $client,
        private readonly PrivateState $store, private readonly NativePolicy $policyMapper,
        private readonly NativeWriteGuard $guard, private readonly PublicationFreshness $freshness)
    {
    }

    public function destination(stdClass $source): array
    {
        $entry = $this->newEntry($source->source_id);
        $url = $this->configuration->canonicalPageUrl($entry->absoluteUrl());
        return ['external_id' => 'statamic-source:'.hash('sha256', $this->configuration->forumOrigin()."\0"
            .$this->configuration->connectionId()."\0".$source->source_id), 'canonical_url' => $url];
    }

    public function apply(array &$state, stdClass $connection, stdClass $policy, stdClass $mapping,
        stdClass $lease, ?stdClass $source, ?string $contentPath): stdClass
    {
        $this->guard->assertRepositoryFence();
        $work = $lease->work;
        $key = $mapping->mapping_id;
        $binding = $state['mappings'][$key] ?? null;
        if ($binding === null || $binding['mapping'] !== $this->client->codec()->encodeJson($mapping)) {
            // Receiver readbacks may advance mapping clocks while identity stays stable.
            if ($binding === null) {
                throw new CmsException('native_ownership_conflict');
            }
            $old = $this->client->codec()->parseJson($binding['mapping'], 262144);
            foreach (['mapping_id', 'resource_id', 'connection_id', 'source_id', 'external_id', 'canonical_url'] as $field) {
                if ($old->$field !== $mapping->$field) {
                    throw new CmsException('identity_conflict');
                }
            }
        }
        if ($work->mapping_id !== $key || $work->source_id !== $mapping->source_id
            || $work->policy_revision !== $policy->policy_revision || $mapping->connection_id !== $connection->connection_id
            || $mapping->presentation_mode !== $policy->presentation_mode
            || $work->source_revision !== $source?->source_revision) {
            throw new CmsException('policy_changed');
        }
        if ($work->action === 'publish_revision' && (! $policy->enabled || $source === null || $contentPath === null
            || $mapping->desired_source_revision !== $source->source_revision)) {
            throw new CmsException('source_ineligible');
        }
        $nativeId = $binding['native_id'] ?? null;
        if ($nativeId === null) {
            if ($mapping->destination_native_id !== null || $work->action !== 'publish_revision') {
                throw new CmsException('native_ownership_conflict');
            }
            // Same allocator used by the native Flat repository; Eloquent's UUID
            // model supports a nonempty explicit native UUID before its first save.
            $nativeId = Stache::generateId();
            $state['mappings'][$key]['native_id'] = $nativeId;
            $this->store->save($state);
        }
        return $this->guard->locked($nativeId, function () use (&$state, $connection, $policy, $mapping, $lease, $source, $contentPath, $nativeId, $key) {
            $this->assertLeaseTime($lease);
            $binding = &$state['mappings'][$key];
            $entry = $this->freshEntry($nativeId);
            if ($entry) {
                $this->owned($entry, $mapping, $nativeId);
                $pending = $binding['pending'] ?? null;
                if ($pending !== null && hash_equals($pending['desired_state'], $this->stateDigest($entry))) {
                    // Crash after native save but before private completion: reconcile
                    // that exact native ID/result, never create another object.
                    $binding['native_revision'] = $this->revision($entry);
                    $binding['generation'] = $pending['generation'];
                    $binding['receipt'] = $pending['receipt'];
                    $binding['work_id'] = $pending['work_id'];
                    $binding['source_revision'] = $pending['source_revision'];
                    $binding['result'] = $this->client->codec()->encodeJson($this->recoveredResult($entry, $pending));
                    $this->freshness->refresh($entry, $mapping->resource_id);
                    unset($binding['pending']);
                    $this->store->save($state);
                }
                if (! isset($binding['native_revision']) || $binding['native_revision'] !== $this->revision($entry)) {
                    throw new CmsException('native_ownership_conflict');
                }
            } elseif (isset($binding['native_revision'])) {
                throw new CmsException('native_ownership_conflict');
            }
            $generation = $binding['generation'] ?? 0;
            if ($lease->lease_generation < max($generation, $binding['seen_generation'] ?? 0)) {
                throw new CmsException('lease_conflict');
            }
            if ($lease->lease_generation === $generation && isset($binding['result'])) {
                $result = $this->client->codec()->parseJson($binding['result'], 262144);
                if ($binding['work_id'] !== $lease->work->work_id || $binding['source_revision'] !== $lease->work->source_revision) {
                    throw new CmsException('lease_conflict');
                }
                return $result;
            }
            if ($entry === null) {
                $entry = $this->newEntry($mapping->source_id)->id($nativeId);
                $entry->data(array_merge($this->policyMapper->initialDefaults($entry), $entry->data()->all()));
            }
            app(\CodeWorksLabs\DiscussionBridgeStatamic\Support\TemplateIntegration::class)
                ->verify($entry->template(), 'from_discourse', $policy->presentation_mode);
            $this->guard->capture($entry);
            if ($entry->absoluteUrl() !== $mapping->canonical_url || Entry::findByUri($entry->uri(), $entry->locale())?->id() !== null
                && Entry::findByUri($entry->uri(), $entry->locale())->id() !== $nativeId) {
                throw new CmsException('url_collision');
            }
            $receipt = (object) ['schema_version' => 'db-native-ownership-1', 'forum_origin' => $this->configuration->forumOrigin(),
                'connection_id' => $connection->connection_id, 'mapping_id' => $key, 'resource_id' => $mapping->resource_id,
                'source_id' => $mapping->source_id, 'external_id' => $mapping->external_id, 'native_id' => $nativeId,
                'canonical_url' => $mapping->canonical_url, 'source_revision' => $source?->source_revision ?? $entry->get('discussionbridge_source_revision'),
                'last_lease_generation' => $lease->lease_generation, 'move_id' => null, 'proof_nonce' => null];
            $receiptHash = $this->client->codec()->nativeOwnershipReceiptSha256($receipt);
            $data = $entry->data()->all();
            if ($lease->work->action === 'publish_revision') {
                $html = file_get_contents($contentPath);
                if (! is_string($html) || strlen($html) !== $source->content_bytes || hash('sha256', $html) !== $source->content_sha256) {
                    throw new CmsException('content_hash_mismatch');
                }
                app(\CodeWorksLabs\DiscussionBridgeStatamic\Publication\NativePublication::class)
                    ->sourceTopicUrl($source->topic_url, (int) $source->topic_id);
                $content = app(HtmlSanitizer::class)->sanitizeArticle($html, $source->topic_url);
                $authors = implode(', ', array_map(fn ($author) => $author->name, $source->source_authors));
                $content .= '<hr><aside class="discussionbridge-publication"><p><strong>Published from <a href="'
                    .$this->escape($source->topic_url).'">'.$this->escape($connection->forum_name).'</a></strong></p><p>Source author: '
                    .$this->escape($authors).($source->source_updated_at === null ? '' : ' · Updated <time datetime="'
                    .$this->escape($source->source_updated_at).'">'.$this->escape($source->source_updated_at).'</time>')
                    .' · Revision '.$this->escape($source->source_revision).'</p></aside>';
                if (config('discussionbridge.rich_content') === null && is_string($entry->get('content'))) {
                    $content .= PresentationAssets::nativeMarkup($entry->get('content'));
                }
                $discussion = match ($policy->presentation_mode) {
                    'simple' => app(StandalonePresenter::class)->simple((int) $source->topic_id, $connection->forum_name),
                    'full' => app(PresentationChrome::class)->standardTopicEmbed($source->topic_id, $this->configuration->forumOrigin(), $connection->forum_name),
                    'interactive' => app(PresentationChrome::class)->discussion($source->topic_id, $source->topic_url, $this->configuration->forumOrigin(), true, $connection->forum_name),
                };
                $data = array_merge($data, $this->policyMapper->assignments($entry, $policy), [
                    'title' => $source->title, 'content' => $content, 'discussionbridge_mode' => $policy->presentation_mode,
                    'discussionbridge_topic_id' => $source->topic_id, 'discussionbridge_topic_url' => $source->topic_url,
                    'discussionbridge_forum_name' => $connection->forum_name, 'discussionbridge_source_revision' => $source->source_revision,
                    'discussionbridge_source_created_at' => $source->source_created_at, 'discussionbridge_source_updated_at' => $source->source_updated_at,
                    'discussionbridge_source_authors' => array_map(fn ($author) => (array) $author, $source->source_authors),
                    'discussionbridge_source_status' => 'active', 'discussionbridge_cms_discussion_html' => $discussion,
                ]);
                if ($source->source_created_at !== null && $entry->collection()->dated()) {
                    $entry->date(CarbonImmutable::parse($source->source_created_at));
                }
            } else {
                $data['discussionbridge_source_status'] = 'withdrawn';
            }
            $entry->data(array_merge($data, ['discussionbridge_cms_owned' => true, 'discussionbridge_native_publication' => true,
                'discussionbridge_cms_mapping_id' => $key, 'discussionbridge_resource_id' => $mapping->resource_id,
                'discussionbridge_cms_receipt_sha256' => $receiptHash, 'discussionbridge_complete_content' => true,
                'discussionbridge_publish' => false, 'discussionbridge_cms_generation' => $lease->lease_generation]));
            $entry->published($lease->work->action === 'publish_revision');
            $this->guard->stage($entry);
            $binding['pending'] = ['desired_state' => $this->stateDigest($entry), 'receipt' => $this->client->codec()->encodeJson($receipt),
                'generation' => $lease->lease_generation, 'work_id' => $lease->work->work_id, 'source_revision' => $lease->work->source_revision,
                'expected_revision' => $binding['native_revision'] ?? null, 'action' => $lease->work->action,
                'representation' => $source === null ? null : $this->client->codec()->encodeJson((object) ['kind' => 'full', 'source_content_sha256' => $source->content_sha256])];
            $this->store->save($state);
            $save = function () use ($entry, $lease, $mapping) {
                $this->assertLeaseTime($lease);
                if ($entry->save() === false) {
                    throw new CmsException('native_service_unavailable');
                }
                $this->assertLeaseTime($lease);
                $this->freshness->refresh($entry, $mapping->resource_id);
            };
            if ($entry instanceof \Statamic\Eloquent\Entries\Entry) {
                try {
                    $entry->toModel()->getConnection()->transaction($save);
                } catch (\Throwable $error) {
                    $uri = $entry->uri();
                    foreach (['eloquent-entry-'.$entry->id(), 'eloquent-entry-'.$uri,
                        'eloquent-entry-'.md5(urlencode($uri)), 'eloquent-entry-'.md5(urlencode($uri)).'-'.$entry->locale()] as $cacheKey) {
                        \Statamic\Facades\Blink::forget($cacheKey);
                    }
                    throw $error;
                }
            } else {
                $save();
            }
            $this->owned($entry, $mapping, $nativeId);
            if (! hash_equals($binding['pending']['desired_state'], $this->stateDigest($entry))) {
                throw new CmsException('native_ownership_conflict');
            }
            $nativeRevision = $this->revision($entry);
            $result = (object) ['kind' => $lease->work->action, 'native_id' => $nativeId, 'external_id' => $mapping->external_id,
                'canonical_url' => $mapping->canonical_url, 'native_revision' => $nativeRevision, 'ownership_receipt_sha256' => $receiptHash];
            if ($source !== null) {
                $result->representation = (object) ['kind' => 'full', 'source_content_sha256' => $source->content_sha256];
            }
            $this->client->codec()->validate('SyncResult', $result);
            $binding['native_revision'] = $nativeRevision;
            $binding['generation'] = $lease->lease_generation;
            $binding['receipt'] = $this->client->codec()->encodeJson($receipt);
            $binding['work_id'] = $lease->work->work_id;
            $binding['source_revision'] = $lease->work->source_revision;
            $binding['result'] = $this->client->codec()->encodeJson($result);
            $binding['synchronized_at'] = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.v\Z');
            unset($binding['pending']);
            $this->store->save($state);
            return $result;
        });
    }

    public function revision($entry): string
    {
        if ($entry instanceof \Statamic\Eloquent\Entries\Entry) {
            $fresh = $entry->model()?->fresh();
            if (! $fresh) {
                throw new CmsException('native_ownership_conflict');
            }
            return 'sha256:'.hash('sha256', json_encode($fresh->getRawOriginal(), JSON_THROW_ON_ERROR));
        }
        $path = $entry->path();
        if (is_link($path) || ! is_file($path)) {
            throw new CmsException('native_ownership_conflict');
        }
        return 'sha256:'.hash_file('sha256', $path);
    }

    /** Read-only native evidence. The callback runs under the ordinary native lock. */
    public function observe(array $state, stdClass $mapping, callable $inspection): mixed
    {
        $binding = $state['mappings'][$mapping->mapping_id] ?? null;
        if (! is_array($binding)) {
            throw new CmsException('native_restore_required');
        }
        $nativeId = $binding['native_id'] ?? null;
        return $this->guard->locked($nativeId ?? 'attention-mapping:'.$mapping->mapping_id, function () use ($binding, $nativeId, $mapping, $inspection) {
            $entry = $nativeId === null ? null : $this->freshEntry($nativeId);
            foreach (['discussionbridge_cms_mapping_id' => $mapping->mapping_id, 'discussionbridge_resource_id' => $mapping->resource_id] as $field => $identity) {
                $claims = Entry::query()->where($field, $identity)->limit(2)->get();
                $ids = array_values(array_unique(array_map(fn ($item) => (string) $item->id(), $claims->all())));
                if ($entry === null ? $ids !== [] : $ids !== [$nativeId]) throw new CmsException('native_ownership_conflict');
            }
            if ($entry === null) {
                if (isset($binding['native_revision']) || isset($binding['receipt']) || isset($binding['result']) || ($binding['generation'] ?? 0) > 0 || isset($binding['pending'])
                    || $mapping->destination_native_id !== null) throw new CmsException('native_ownership_conflict');
                $uri = parse_url($mapping->canonical_url, PHP_URL_PATH);
                if (! is_string($uri) || Entry::findByUri($uri, Site::default()->handle()) !== null) {
                    throw new CmsException('native_ownership_conflict');
                }
                return $inspection(['object_exists' => false, 'native_id' => $nativeId, 'entry' => null]);
            }
            $this->owned($entry, $mapping, $binding['native_id']);
            $revision = $this->revision($entry); $digest = $this->stateDigest($entry);
            $pending = $binding['pending'] ?? null;
            $evidence = $pending !== null && hash_equals($pending['desired_state'], $digest) ? $pending : $binding;
            if ($evidence === $binding && ($binding['native_revision'] ?? null) !== $revision) {
                throw new CmsException('native_ownership_conflict');
            }
            if (! isset($evidence['receipt'])) throw new CmsException('native_restore_required');
            $receipt = $this->client->codec()->parseJson($evidence['receipt']);
            $this->client->codec()->validate('NativeOwnershipReceipt', $receipt);
            foreach (['mapping_id', 'resource_id', 'source_id', 'external_id', 'canonical_url', 'connection_id'] as $field) {
                if ($receipt->$field !== $mapping->$field) throw new CmsException('native_ownership_conflict');
            }
            $receiptHash = $this->client->codec()->nativeOwnershipReceiptSha256($receipt);
            if ($receipt->native_id !== $binding['native_id'] || $receipt->forum_origin !== $this->configuration->forumOrigin()
                || $receiptHash !== $entry->get('discussionbridge_cms_receipt_sha256')
                || $receipt->last_lease_generation !== $entry->get('discussionbridge_cms_generation')
                || $receipt->move_id !== null || $receipt->proof_nonce !== null) throw new CmsException('native_ownership_conflict');
            $result = $evidence === $pending ? $this->recoveredResult($entry, $pending)
                : $this->client->codec()->parseJson($binding['result'] ?? 'null');
            $this->client->codec()->validate('SyncResult', $result);
            if ($result->native_revision !== $revision || $result->ownership_receipt_sha256 !== $receiptHash
                || $result->native_id !== $binding['native_id']) throw new CmsException('native_ownership_conflict');
            return $inspection(['object_exists' => true, 'native_id' => $binding['native_id'], 'native_revision' => $revision,
                'state_sha256' => $digest, 'receipt' => $receipt, 'result' => $result, 'entry' => $entry,
                'work_id' => $evidence['work_id'], 'source_revision' => $evidence['source_revision']]);
        });
    }

    /** Reconcile an old uncertain write under the durable native lock, without new publication authority. */
    public function reconcile(array &$state, string $mappingId): void
    {
        $binding = $state['mappings'][$mappingId] ?? null;
        if ($binding === null || ! isset($binding['native_id'])) {
            return;
        }
        $this->guard->locked($binding['native_id'], function () use (&$state, $mappingId) {
            $binding = &$state['mappings'][$mappingId];
            $mapping = $this->client->codec()->parseJson($binding['mapping'], 262144);
            $entry = $this->freshEntry($binding['native_id']);
            $pending = $binding['pending'] ?? null;
            if ($entry === null) {
                if (isset($binding['native_revision']) || $pending !== null && $pending['expected_revision'] !== null) {
                    throw new CmsException('native_ownership_conflict');
                }
                unset($binding['pending']);
                $this->store->save($state);
                return;
            }
            $this->owned($entry, $mapping, $binding['native_id']);
            if ($pending !== null && hash_equals($pending['desired_state'], $this->stateDigest($entry))) {
                $binding['native_revision'] = $this->revision($entry);
                $binding['generation'] = $pending['generation'];
                $binding['receipt'] = $pending['receipt'];
                $binding['work_id'] = $pending['work_id'];
                $binding['source_revision'] = $pending['source_revision'];
                $binding['result'] = $this->client->codec()->encodeJson($this->recoveredResult($entry, $pending));
                unset($binding['pending']);
            } elseif (($pending['expected_revision'] ?? $binding['native_revision'] ?? null) !== $this->revision($entry)) {
                throw new CmsException('native_ownership_conflict');
            } else {
                unset($binding['pending']);
            }
            $this->store->save($state);
        });
    }

    private function recoveredResult($entry, array $pending): stdClass
    {
        $receipt = $this->client->codec()->parseJson($pending['receipt'], 262144);
        $result = (object) ['kind' => $pending['action'], 'native_id' => $receipt->native_id, 'external_id' => $receipt->external_id,
            'canonical_url' => $receipt->canonical_url, 'native_revision' => $this->revision($entry),
            'ownership_receipt_sha256' => $this->client->codec()->nativeOwnershipReceiptSha256($receipt)];
        if ($pending['representation'] !== null) {
            $result->representation = $this->client->codec()->parseJson($pending['representation']);
        }
        $this->client->codec()->validate('SyncResult', $result);
        return $result;
    }

    public function freshEntry(string $id)
    {
        $entry = Entry::find($id);
        if ($entry instanceof \Statamic\Eloquent\Entries\Entry) {
            $model = $entry->model()?->fresh();
            return $model ? \Statamic\Eloquent\Entries\Entry::fromModel($model) : null;
        }
        if ($entry === null || ! is_file($entry->path()) || is_link($entry->path())) {
            return null;
        }
        return Stache::store('entries')->store($entry->collectionHandle())
            ->makeItemFromFile($entry->path(), file_get_contents($entry->path()))->syncOriginal();
    }

    public function stateDigest($entry): string
    {
        return NativeSnapshot::digest([...$entry->getCurrentDirtyStateAttributes(), 'blueprint' => $entry->blueprint()->handle()]);
    }

    public function staticSnapshot(array $state, string $mappingId): array
    {
        $binding = $state['mappings'][$mappingId];
        if (! isset($binding['native_id'])) {
            return ['exists' => false, 'binding' => $binding];
        }
        return $this->guard->locked($binding['native_id'], function () use ($binding) {
            $entry = $this->freshEntry($binding['native_id']);
            if ($entry === null) {
                if (isset($binding['native_revision'])) {
                    throw new CmsException('native_ownership_conflict');
                }
                return ['exists' => false, 'binding' => $binding];
            }
            if ($entry instanceof \Statamic\Eloquent\Entries\Entry || ($binding['native_revision'] ?? null) !== $this->revision($entry)) {
                throw new CmsException('native_ownership_conflict');
            }
            return ['exists' => true, 'binding' => $binding, 'path' => $entry->path(),
                'bytes' => base64_encode(file_get_contents($entry->path())), 'revision' => $this->revision($entry)];
        });
    }

    /** Restore the exact native prior owned file using its existing native store. */
    public function restoreStatic(array &$state, string $mappingId, array $snapshot): bool
    {
        if (! $snapshot['exists'] || ! isset($snapshot['binding']['last_verified'])) {
            return false;
        }
        $binding = $state['mappings'][$mappingId];
        return $this->guard->locked($binding['native_id'], function () use (&$state, $mappingId, $snapshot, $binding) {
            $current = $this->freshEntry($binding['native_id']);
            if (! $current || $current instanceof \Statamic\Eloquent\Entries\Entry
                || $this->revision($current) !== $binding['native_revision'] || $current->path() !== $snapshot['path']) {
                throw new CmsException('native_ownership_conflict');
            }
            $store = Stache::store('entries')->store($current->collectionHandle());
            $bytes = base64_decode($snapshot['bytes'], true);
            if (! is_string($bytes) || 'sha256:'.hash('sha256', $bytes) !== $snapshot['revision']) {
                throw new CmsException('native_restore_required');
            }
            $restored = $store->makeItemFromFile($snapshot['path'], $bytes)->syncOriginal();
            $mapping = $this->client->codec()->parseJson($binding['mapping'], 262144);
            $this->owned($restored, $mapping, $binding['native_id']);
            // Already under the native fence. The native store restores its own
            // file/index/cache semantics without inventing a newer ownership receipt.
            $store->save($restored);
            $this->freshness->refresh($restored, $mapping->resource_id);
            $store->forgetItem($restored->id());
            if ($this->revision($this->freshEntry($restored->id())) !== $snapshot['revision']) {
                throw new CmsException('native_restore_required');
            }
            $previous = $snapshot['binding'];
            $previous['seen_generation'] = max($previous['seen_generation'] ?? 0, $binding['seen_generation'] ?? 0);
            $previous['mapping'] = $binding['mapping'];
            $previous['static_transaction'] = $binding['static_transaction'];
            $state['mappings'][$mappingId] = $previous;
            $this->store->save($state);
            return true;
        });
    }

    private function newEntry(string $sourceId)
    {
        $collection = Collection::findByHandle('discussionbridge');
        // Structured pages/propagation touch additional native stores and require
        // a corresponding native journal, not a fabricated SQL-only boundary.
        if (! $collection || $collection->orderable() || $collection->propagate() || $collection->autoGeneratesTitles()) {
            throw new CmsException('presentation_unsupported');
        }
        $entry = Entry::make()->collection($collection)->locale(Site::default()->handle())
            ->slug('source-'.substr(hash('sha256', $sourceId), 0, 32))->published(true);
        return $entry->set('blueprint', $entry->blueprint()->handle());
    }

    private function owned($entry, stdClass $mapping, string $nativeId): void
    {
        if ((string) $entry->id() !== $nativeId || $entry->get('discussionbridge_cms_owned') !== true
            || $entry->get('discussionbridge_cms_mapping_id') !== $mapping->mapping_id
            || $entry->get('discussionbridge_resource_id') !== $mapping->resource_id
            || $entry->absoluteUrl() !== $mapping->canonical_url) {
            throw new CmsException('native_ownership_conflict');
        }
    }

    private function assertLeaseTime(stdClass $lease): void
    {
        if (CarbonImmutable::now('UTC')->greaterThanOrEqualTo(CarbonImmutable::parse($lease->expires_at))) {
            throw new CmsException('lease_expired');
        }
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
