<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

use Carbon\CarbonImmutable;
use CodeWorksLabs\DiscussionBridgeStatamic\Delivery\SourcePublication;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/** Ordinary To source moves, under the existing source/native writer. */
class ToSourceMoves
{
    public function __construct(private readonly PrivateState $store, private readonly CmsClient $client,
        private readonly Configuration $configuration, private readonly NativePolicy $native,
        private readonly ToSourceSnapshot $snapshots, private readonly ToSourceArticleProfile $profile,
        private readonly ToSourcePublicVerifier $verifier) {}

    public function before(array &$state, object $row, $entry): bool
    {
        $slot = $state['to'][$row->external_id] ?? [];
        if (isset($slot['source_move'])) {
            throw new CmsException('native_source_move_requires_reconciliation');
        }
        $newUrl = $this->snapshots->canonical($entry);
        if ($newUrl === $row->canonical_url) {
            return false;
        }
        if (! isset($slot['accepted']) || isset($slot['pending']) || isset($slot['closure'])) {
            throw new CmsException('native_source_move_requires_reconciliation');
        }
        if (! in_array(get_class($entry), [FlatOperationEntry::class, DatabaseOperationEntry::class], true)) {
            throw new CmsException('native_source_entry_operation_unsupported');
        }
        $accepted = $slot['accepted'];
        $record = $this->client->codec()->parseJson($accepted['record']);
        $this->client->codec()->validate('ToRecord', $record);
        $before = $this->snapshots->read($row, $entry->getOriginal('path'));
        $this->snapshots->assertOriginal($entry, $before['entry']);
        if ($before['snapshot']['canonical_url'] !== $row->canonical_url
            || $record->canonical_url !== $row->canonical_url || $record->external_id !== $row->external_id
            || $accepted['source_revision'] !== $row->cms_source_revision
            || $accepted['resource_id'] !== $row->resource_id || $accepted['topic_id'] !== $row->topic_id) {
            throw new CmsException('native_ownership_conflict');
        }
        $collection = $entry->collection();
        if ($collection->orderable() || $collection->propagate() || $entry->hasOrigin() || $collection->mount() !== null
            || $entry->locale() !== $row->site_handle || $entry->collectionHandle() !== $row->collection_handle
            || ! in_array($entry->template(), config('discussionbridge.cms_source_templates', []), true)) {
            throw new CmsException('native_source_route_or_template_unsupported');
        }
        $original = $accepted['native_source'] ?? null;
        if (! is_array($original) || ! is_string($before['entry']->get('content'))
            || hash('sha256', $before['entry']->get('content')) !== $original['content_sha256']
            || base64_encode($before['entry']->get('content')) !== $original['content_base64']) {
            throw new CmsException('native_source_move_capture_unavailable');
        }
        // Reusing the EXACT accepted edition is not admission of a new HTML
        // recipe. Both old and proposed native source must match its full digest.
        $source = SourcePublication::fromEntry($before['entry'], $this->configuration, $original['article_url']);
        $proposed = SourcePublication::fromEntry($entry, $this->configuration, $original['article_url']);
        // Before/proposed Entries share a native Blink URI-cache key. The
        // actual proposed canonical was independently resolved above; keep
        // that exact URL in the proposed capture while its article base stays
        // the original edition's URL and full article bytes stay unchanged.
        $proposed['record']['canonical_url'] = $newUrl;
        $this->sameArticle($record, $source);
        $this->sameArticle($record, $proposed);
        $connection = $this->connection($state);
        $this->detail($row, $accepted);
        $id = (string) Str::uuid();
        $revision = 'move:'.hash('sha256', $accepted['source_revision']."\0".$id."\0".$newUrl."\0".$source['record']['source_publication']['content_sha256']);
        $updated = ToSourceTimestamp::instant($proposed['record']['source_publication']['updated_at']);
        $projected = $this->project($record, $newUrl, $revision, $updated);
        $move = ['capture_id' => $id, 'stage' => 'intent', 'row_before' => (array) $row,
            'accepted_before' => $accepted, 'native_before' => $before['snapshot'], 'source' => $source,
            'proposed_source' => $proposed, 'new_url' => $newUrl,
            'proposed_dirty' => $entry->getCurrentDirtyStateAttributes(),
            'proposed_persisted' => $this->snapshots->persistedPayload($entry),
            'new_path' => $entry instanceof \Statamic\Eloquent\Entries\Entry ? null : $entry->buildPath(),
            'proposed_route' => $this->snapshots->route($entry), 'new_source_revision' => $revision,
            'projected_record' => $this->client->codec()->encodeJson($projected),
            'scope' => ['connection_id' => $connection->connection_id, 'scope_revision' => $connection->scope_revision,
                'publication_lane' => $connection->publication_lane, 'profile' => $connection->profile],
            'prepare_key' => 'source-move-prepare:'.$id, 'finalize_key' => 'source-move-finalize:'.$id,
            'abort_key' => 'source-move-abort:'.$id];
        if ($this->native->profile() === 'statamic_ssg') {
            app(ToSourceStatic::class)->before($state, $move, $before['entry']);
        }
        $state['to'][$row->external_id]['source_move'] = $move;
        $this->store->save($state);
        $response = (new ReplayJournal($this->client, $this->store))->mutate($state, $move['prepare_key'], 'V4-28',
            ['resource_id' => $accepted['resource_id']], (object) ['expected_source_revision' => $accepted['source_revision'],
                'old_url' => $row->canonical_url, 'new_url' => $newUrl, 'external_id' => $row->external_id]);
        if ($response->resource_id !== $accepted['resource_id'] || $response->expected_source_revision !== $accepted['source_revision']
            || $response->old_url !== $row->canonical_url || $response->new_url !== $newUrl || $response->state !== 'prepared') {
            throw new CmsException('returned_identity_mismatch');
        }
        $state['to'][$row->external_id]['source_move']['prepared'] = $this->client->codec()->encodeJson($response);
        $state['to'][$row->external_id]['source_move']['stage'] = 'native_intent';
        $this->store->save($state);
        return true;
    }

    public function complete(object $row, string $witness): void
    {
        $state = $this->store->read();
        $move = &$state['to'][$row->external_id]['source_move'];
        $move['native_operation_completed'] = $witness;
        $this->store->save($state);
        try {
            $actual = $this->snapshots->read($row, $move['new_path']);
        } catch (\Throwable) {
            $actual = null;
        }
        if ($actual !== null && $actual['snapshot']['canonical_url'] === $move['new_url']) {
            $this->assertAfter($move, $actual);
            $move['native_after'] = $actual['snapshot'];
            $move['stage'] = 'native_applied';
            $move['synchronized_at'] = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.v\Z');
            $this->store->save($state);
            $this->projection($move, $row, $actual);
            $this->store->save($state);
            DB::table('discussionbridge_deliveries')->where('id', $row->id)->where('canonical_url', $row->canonical_url)
                ->update(['status' => 'pending', 'next_attempt_at' => now(), 'last_error' => null, 'updated_at' => now()]);
            return;
        }
        $old = $this->snapshots->read($row,
            $move['native_before']['storage']['kind'] === 'file' ? $move['native_before']['storage']['path'] : null);
        if ($old['snapshot'] === $move['native_before']) {
            $move['stage'] = 'unapplied';
            $move['native_unchanged'] = $old['snapshot'];
            $this->store->save($state);
            $this->abort($state, $row);
        } else {
            $move['stage'] = 'native_uncertain';
            $this->store->save($state);
            throw new CmsException('native_source_move_requires_reconciliation');
        }
    }

    public function notInvoked(object $row): void
    {
        $state = $this->store->read();
        if (! isset($state['to'][$row->external_id]['source_move'])) {
            return;
        }
        $state['to'][$row->external_id]['source_move']['native_operation_completed'] = 'not_invoked';
        $this->store->save($state);
        DB::table('discussionbridge_deliveries')->where('id', $row->id)
            ->update(['status' => 'pending', 'next_attempt_at' => now(), 'updated_at' => now()]);
    }

    public function recover(array &$state): void
    {
        foreach ($state['to'] ?? [] as $external => $slot) {
            if (! isset($slot['source_move'])) {
                continue;
            }
            $row = DB::table('discussionbridge_deliveries')->where('external_id', $external)->first();
            if ($row === null) {
                throw new CmsException('native_restore_required');
            }
            $this->recoverOne($state, $row);
        }
    }

    public function recoverOne(array &$state, object $row): void
    {
        if (! isset($state['to'][$row->external_id]['source_move'])) {
            return;
        }
        $move = &$state['to'][$row->external_id]['source_move'];
        if ($move['stage'] === 'finalized') {
            $this->repairQueue($row, $move, $state['to'][$row->external_id]['accepted']);
            unset($state['to'][$row->external_id]['source_move']);
            $this->store->save($state);
            return;
        }
        if ($move['stage'] === 'unapplied') {
            $this->abort($state, $row);
            return;
        }
        if ($move['stage'] === 'intent' && ($move['native_operation_completed'] ?? null) === 'not_invoked') {
            $this->connection($state);
            $this->detail($row, $move['accepted_before']);
            $old = $this->snapshots->read($row, $move['native_before']['storage']['path'] ?? null);
            if ($old['snapshot'] !== $move['native_before']) {
                throw new CmsException('native_ownership_conflict');
            }
            $saved = $state['mutations'][$move['prepare_key']];
            $prepared = (new ReplayJournal($this->client, $this->store))->mutate($state, $move['prepare_key'], 'V4-28',
                ['resource_id' => $move['accepted_before']['resource_id']], $this->client->codec()->parseJson($saved['body']));
            if ($prepared->old_url !== $row->canonical_url || $prepared->new_url !== $move['new_url']
                || $prepared->expected_source_revision !== $move['accepted_before']['source_revision']) {
                throw new CmsException('returned_identity_mismatch');
            }
            $move['prepared'] = $this->client->codec()->encodeJson($prepared);
            $move['stage'] = 'unapplied';
            $this->store->save($state);
            $this->abort($state, $row);
            return;
        }
        if ($move['stage'] === 'native_intent') {
            try {
                $actual = $this->snapshots->read($row, $move['new_path']);
            } catch (\Throwable) {
                if (! isset($move['native_operation_completed'])) {
                    return; // Missing new path alone is not an operation witness.
                }
                $actual = $this->snapshots->read($row, $move['native_before']['storage']['path'] ?? null);
            }
            if ($actual['snapshot']['canonical_url'] === $move['new_url']) {
                $this->complete($row, 'recovered_applied');
                $state = $this->store->read();
            } elseif (isset($move['native_operation_completed']) && $actual['snapshot'] === $move['native_before']) {
                $move['stage'] = 'unapplied';
                $this->store->save($state);
                $this->abort($state, $row);
            }
        }
    }

    public function deliver(array &$state, object $row): array
    {
        $move = &$state['to'][$row->external_id]['source_move'];
        if ($move['stage'] === 'finalized') {
            $this->repairQueue($row, $move, $state['to'][$row->external_id]['accepted']);
            return $this->values($state['to'][$row->external_id]['accepted']);
        }
        if ($move['stage'] === 'unapplied') {
            $this->abort($state, $row);
            return $this->values($move['accepted_before']);
        }
        if (! in_array($move['stage'], ['native_applied', 'verified'], true)) {
            throw new CmsException('native_source_move_requires_reconciliation');
        }
        $connection = $this->connection($state);
        if (['connection_id' => $connection->connection_id, 'scope_revision' => $connection->scope_revision,
            'publication_lane' => $connection->publication_lane, 'profile' => $connection->profile] !== $move['scope']) {
            throw new CmsException('scope_denied');
        }
        $actual = $this->snapshots->read($row, $move['new_path']);
        $this->assertAfter($move, $actual);
        if ($actual['snapshot'] !== $move['native_after']) {
            throw new CmsException('native_ownership_conflict');
        }
        $prepared = $this->client->codec()->parseJson($move['prepared']);
        $projection = $move['projection'];
        $notBefore = $move['synchronized_at'];
        if ($this->native->profile() === 'statamic_ssg') {
            $notBefore = app(ToSourceStatic::class)->publish($state, $row, $move, $projection);
        }
        $journal = new ReplayJournal($this->client, $this->store);
        if (! isset($state['mutations'][$move['finalize_key']])) {
            $this->detail($row, $move['accepted_before']);
            try {
                $verification = $this->verifier->verify($projection, max($notBefore, $prepared->prepared_at));
            } catch (\Throwable $error) {
                if ($this->native->profile() === 'statamic_ssg') {
                    app(ToSourceStatic::class)->restoreArtifact($state, $row, $move);
                }
                throw $error;
            }
            $again = $this->snapshots->read($row, $move['new_path']);
            if ($again['snapshot'] !== $move['native_after']) {
                throw new CmsException('native_ownership_conflict');
            }
            $journal->prepare($state, $move['finalize_key'], 'V4-29',
                ['resource_id' => $prepared->resource_id, 'move_id' => $prepared->move_id], (object) [
                    'move_id' => $prepared->move_id, 'proof_nonce' => $prepared->proof_nonce,
                    'expected_source_revision' => $prepared->expected_source_revision,
                    'verification' => $verification, 'new_source_revision' => $move['new_source_revision'],
                    'source_updated_at' => $this->client->codec()->parseJson($move['projected_record'])->source_updated_at]);
            $move['stage'] = 'verified';
            $this->store->save($state);
        }
        $input = $this->client->codec()->parseJson($state['mutations'][$move['finalize_key']]['body']);
        $result = $journal->mutate($state, $move['finalize_key'], 'V4-29',
            ['resource_id' => $prepared->resource_id, 'move_id' => $prepared->move_id], $input);
        $this->accept($state, $row, $result);
        return $this->values($state['to'][$row->external_id]['accepted']);
    }

    private function assertAfter(array $move, array $actual): void
    {
        $entry = $actual['entry'];
        if ($entry->published() !== true || $entry->status() !== 'published' || $entry->get('discussionbridge_publish') !== true
            || $entry->get('discussionbridge_native_publication') === true
            || $actual['snapshot']['canonical_url'] !== $move['new_url']
            || $actual['snapshot']['route'] !== $move['proposed_route']
            || ToSourceSnapshot::digest($this->snapshots->persistedPayload($entry)) !== ToSourceSnapshot::digest($move['proposed_persisted'])
            || $actual['snapshot']['render_inputs'] !== $move['native_before']['render_inputs']) {
            throw new CmsException('native_ownership_conflict');
        }
        $record = $this->client->codec()->parseJson($move['accepted_before']['record']);
        $source = SourcePublication::fromEntry($entry, $this->configuration, $move['source']['native_source']['article_url']);
        $this->sameArticle($record, $source);
        if ($source['native_source']['content_base64'] !== $move['source']['native_source']['content_base64']) {
            throw new CmsException('native_ownership_conflict');
        }
    }

    private function sameArticle(stdClass $record, array $source): void
    {
        $current = $source['record'];
        if ($record->title !== $current['title'] || $record->source_content_sha256 !== $current['source_publication']['content_sha256']
            || $record->source_content_bytes !== $current['source_publication']['content_bytes']
            || $record->source_created_at !== ToSourceTimestamp::instant($current['source_publication']['created_at'])
            || $this->client->codec()->encodeJson($record->source_authors ?? []) !== $this->client->codec()->encodeJson(
                array_map(fn ($author) => (object) ['id' => $author['id'], 'name' => $author['name']], $current['source_authors'] ?? []))
            || ($record->primary_source_author_id ?? null) !== ($current['primary_source_author_id'] ?? null)) {
            throw new CmsException('native_ownership_conflict');
        }
    }

    private function project(stdClass $record, string $url, string $revision, ?string $updated): stdClass
    {
        if ($record->representation->kind === 'generated_excerpt') {
            return $this->client->codec()->projectGeneratedReadMoreMove($record, $url, $revision, $updated);
        }
        $projected = $this->client->codec()->parseJson($this->client->codec()->encodeJson($record));
        $projected->canonical_url = $url;
        $projected->expected_source_revision = $record->source_revision;
        $projected->source_revision = $revision;
        $projected->source_updated_at = $updated;
        return $this->client->codec()->validateGeneratedEnvelope($projected);
    }

    private function projection(array &$move, object $row, array $actual): void
    {
        $prepared = $this->client->codec()->parseJson($move['prepared']);
        $source = SourcePublication::fromEntry($actual['entry'], $this->configuration, $move['source']['native_source']['article_url']);
        $record = $this->client->codec()->parseJson($move['projected_record']);
        $record->source_updated_at = ToSourceTimestamp::instant($source['record']['source_publication']['updated_at']);
        $this->client->codec()->validate('ToRecord', $record);
        $move['projected_record'] = $this->client->codec()->encodeJson($record);
        $receipt = $this->receipt($row, $move, $actual['snapshot'], $source, $move['new_source_revision'], $prepared);
        $move['receipt'] = $receipt;
        $move['projection'] = ['profile' => ToSourceArticleProfile::PROFILE, 'row' => (array) $row,
            'snapshot' => $actual['snapshot'], 'source' => $source, 'source_revision' => $move['new_source_revision'],
            'move_id' => $prepared->move_id, 'proof_nonce' => $prepared->proof_nonce,
            'receipt_sha256' => ToSourceOwnership::sha256($receipt)];
        $this->profile->publish($move['projection']);
    }

    private function receipt(object $row, array $move, array $snapshot, array $source, string $revision, ?stdClass $prepared): array
    {
        $record = $source['record'];
        $accepted = $move['accepted_before'];
        return ['format' => ToSourceOwnership::FORMAT, 'connection_id' => $this->configuration->connectionId(),
            'forum_origin' => $this->configuration->forumOrigin(), 'native_origin' => $this->configuration->siteOrigin(),
            'publication_lane' => $move['scope']['publication_lane'], 'entry_id' => $row->entry_id,
            'site_handle' => $row->site_handle, 'collection_handle' => $row->collection_handle,
            'external_id' => $row->external_id, 'resource_id' => $accepted['resource_id'], 'topic_id' => $accepted['topic_id'],
            'current_source_revision' => $revision, 'canonical_url' => $snapshot['canonical_url'],
            'complete_prepared_sha256' => $record['source_publication']['content_sha256'],
            'complete_prepared_bytes' => (string) $record['source_publication']['content_bytes'],
            'title_sha256' => hash('sha256', $record['title']), 'authors_sha256' => ToSourceSnapshot::digest($record['source_authors'] ?? []),
            'primary_author_id' => $record['primary_source_author_id'] ?? null,
            'source_created_at' => ToSourceTimestamp::instant($record['source_publication']['created_at']),
            'source_updated_at' => ToSourceTimestamp::instant($record['source_publication']['updated_at']),
            'storage_kind' => $snapshot['storage']['kind'], 'actual_storage_snapshot_sha256' => $snapshot['storage_sha256'],
            'actual_route_snapshot_sha256' => $snapshot['route_sha256'],
            'source_render_inputs_sha256' => $snapshot['render_inputs_sha256'],
            'move_id' => $prepared?->move_id, 'issued_public_nonce' => $prepared?->proof_nonce];
    }

    private function abort(array &$state, object $row): void
    {
        $move = &$state['to'][$row->external_id]['source_move'];
        if ($move['stage'] !== 'unapplied' || ! isset($move['native_operation_completed'])) {
            throw new CmsException('native_source_move_requires_reconciliation');
        }
        $this->connection($state);
        $this->detail($row, $move['accepted_before']);
        $prepared = $this->client->codec()->parseJson($move['prepared']);
        $old = $this->snapshots->read($row, $move['native_before']['storage']['path'] ?? null);
        if ($old['snapshot'] !== $move['native_before']) {
            throw new CmsException('native_ownership_conflict');
        }
        $receipt = $this->receipt($row, $move, $old['snapshot'], $move['source'], $move['accepted_before']['source_revision'], $prepared);
        $move['abort_receipt'] = $receipt;
        $this->store->save($state);
        $response = (new ReplayJournal($this->client, $this->store))->mutate($state, $move['abort_key'], 'V4-30',
            ['resource_id' => $prepared->resource_id, 'move_id' => $prepared->move_id], (object) [
                'move_id' => $prepared->move_id, 'expected_source_revision' => $prepared->expected_source_revision,
                'native_change_applied' => false, 'old_url' => $prepared->old_url,
                'native_ownership_receipt_sha256' => ToSourceOwnership::sha256($receipt)]);
        if ($response->state !== 'aborted' || $response->move_id !== $prepared->move_id) {
            throw new CmsException('returned_identity_mismatch');
        }
        $move['stage'] = 'aborted';
        $state['to'][$row->external_id]['source_move_history'][$move['capture_id']] = $move;
        unset($state['to'][$row->external_id]['source_move']);
        $this->store->save($state);
    }

    private function accept(array &$state, object $row, stdClass $result): void
    {
        $move = &$state['to'][$row->external_id]['source_move'];
        $prior = $move['accepted_before'];
        if ($result->resource_id !== $prior['resource_id'] || $result->topic_id !== $prior['topic_id']
            || $result->topic_url !== $prior['topic_url'] || $result->direction !== 'to_discourse' || $result->state !== 'active'
            || $result->external_id !== $row->external_id || $result->canonical_url !== $move['new_url']
            || $result->source_revision !== $move['new_source_revision']) {
            throw new CmsException('returned_identity_mismatch');
        }
        $current = $this->client->request('V4-04', ['resource_id' => $prior['resource_id']]);
        if ($this->client->codec()->semanticIdentity($current) !== $this->client->codec()->semanticIdentity($result)) {
            throw new CmsException('native_ownership_conflict');
        }
        $cursor = null;
        $seen = [];
        $cursors = [];
        $found = false;
        $prepared = $this->client->codec()->parseJson($move['prepared']);
        do {
            $page = $this->client->request('V4-31', ['resource_id' => $prior['resource_id']],
                $cursor === null ? new stdClass : (object) ['cursor' => $cursor]);
            $seen[] = $this->client->codec()->encodeJson($page);
            foreach ($page->items as $item) {
                if ($item->url === $move['row_before']['canonical_url'] && $item->move_id === $prepared->move_id) {
                    $found = true;
                }
            }
            $next = $page->next_cursor;
            if ($next !== null && (in_array($next, $cursors, true) || $next === $cursor || count($seen) >= 10000)) {
                throw new CmsException('metadata_unavailable');
            }
            if ($next !== null) {
                $cursors[] = $next;
            }
            $cursor = $next;
        } while ($cursor !== null);
        if (! $found) {
            throw new CmsException('native_source_move_history_unproven');
        }
        $accepted = [...$prior, 'record' => $move['projected_record'], 'source_revision' => $move['new_source_revision'],
            'native_revision' => $move['projection']['source']['record']['source_publication']['revision'],
            'native_source' => $move['projection']['source']['native_source'], 'prepared_source' => $move['projection']['source'],
            'synchronized_at' => $result->synchronized_at, 'acceptance_kind' => 'source_move',
            'mutation_key' => $move['finalize_key'], 'move_result' => $this->client->codec()->encodeJson($result)];
        $move['stage'] = 'finalized';
        $move['result'] = $this->client->codec()->encodeJson($result);
        $move['url_history_pages'] = $seen;
        $state['to'][$row->external_id]['accepted_history'][$move['finalize_key']] = $accepted;
        $state['to'][$row->external_id]['accepted'] = $accepted;
        $state['to'][$row->external_id]['route_edition'] = $move['capture_id'];
        $state['to'][$row->external_id]['source_move_history'][$move['capture_id']] = $move;
        $this->store->save($state);
        $this->repairQueue($row, $move, $accepted);
        unset($state['to'][$row->external_id]['source_move']);
        $this->store->save($state);
    }

    private function repairQueue(object $row, array $move, array $accepted): void
    {
        DB::table('discussionbridge_deliveries')->where('id', $row->id)->where('external_id', $row->external_id)
            ->whereIn('canonical_url', [$move['row_before']['canonical_url'], $move['new_url']])
            ->update(['canonical_url' => $move['new_url'], 'cms_source_revision' => $accepted['source_revision'],
                'cms_native_revision' => $accepted['native_revision'], 'desired_revision' => $accepted['native_revision'], 'updated_at' => now()]);
    }

    private function connection(array &$state): stdClass
    {
        $connection = $this->client->request('V4-01');
        if ($connection->connection_id !== $this->configuration->connectionId()
            || $connection->destination_origin !== $this->configuration->siteOrigin() || $connection->profile !== $this->native->profile()
            || ! in_array('to_discourse', $connection->directions, true)
            || $this->configuration->lane() !== null && $connection->publication_lane !== $this->configuration->lane()) {
            throw new CmsException('scope_denied');
        }
        return $connection;
    }

    private function detail(object $row, array $accepted): void
    {
        $detail = $this->client->request('V4-04', ['resource_id' => $accepted['resource_id']]);
        $record = $this->client->codec()->parseJson($accepted['record']);
        if ($detail->resource_id !== $accepted['resource_id'] || $detail->topic_id !== $accepted['topic_id']
            || $detail->topic_url !== $accepted['topic_url'] || $detail->external_id !== $row->external_id
            || $detail->canonical_url !== $record->canonical_url || $detail->source_revision !== $accepted['source_revision']
            || $detail->state !== 'active' || $detail->direction !== 'to_discourse') {
            throw new CmsException('native_ownership_conflict');
        }
    }

    private function values(array $accepted): array
    {
        return ['resource_id' => $accepted['resource_id'], 'topic_id' => $accepted['topic_id'], 'topic_url' => $accepted['topic_url'],
            'outcome' => $accepted['outcome'], 'forum_name' => $accepted['forum_name'],
            'cms_source_revision' => $accepted['source_revision'], 'cms_native_revision' => $accepted['native_revision'],
            'cms_synchronized_at' => $accepted['synchronized_at']];
    }
}
