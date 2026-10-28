<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

use Illuminate\Support\Str;
use stdClass;

/** Explicit native administrator refresh with exact durable V4-24–27 replay. */
class CatalogProducer
{
    public function __construct(private readonly CmsClient $client, private readonly PrivateState $store,
        private readonly NativePolicy $native)
    {
    }

    public function refresh(string $kind): array
    {
        $this->client->codec()->validate('CatalogKind', $kind);
        return $this->store->locked(function () use ($kind) {
            $state = $this->store->read();
            $connection = $this->client->request('V4-01');
            $this->native->authorize($connection, $connection->policy);
            $identity = ['connection_id' => $connection->connection_id, 'profile' => $connection->profile,
                'destination_origin' => $connection->destination_origin, 'forum_origin' => config('discussionbridge.forum_url')];
            if ($state['binding'] !== null && $state['binding'] !== $identity) {
                throw new CmsException('scope_denied');
            }
            $state['binding'] = $identity;
            $pending = $state['catalog_pending'][$kind] ?? null;
            if ($pending === null) {
                $snapshot = $this->native->catalog($kind);
                foreach ($snapshot['items'] as $item) {
                    $this->client->codec()->validate('CatalogEntry', $item);
                }
                // A stable observed census, not an asserted native atomic SQL
                // snapshot. No picker permission/scope is impersonated.
                if ($snapshot != $this->native->catalog($kind)) {
                    throw new CmsException('metadata_unavailable');
                }
                $pending = ['id' => (string) Str::uuid(), 'native_revision' => $snapshot['native_revision'],
                    'items' => array_map(fn ($item) => (array) $item, $snapshot['items']), 'upload' => null];
                $state['catalog_pending'][$kind] = $pending;
                $this->store->save($state);
            }
            $journal = new ReplayJournal($this->client, $this->store);
            try {
                return $this->upload($state, $journal, $kind);
            } catch (CmsException $error) {
                if ($error->failureCode === 'catalog_upload_expired') {
                    // Confirmed receiver expiry, not an uncertain result. Keep
                    // the old active snapshot and exact old history; next explicit
                    // refresh begins a new upload of this SAME staged census.
                    $state['catalog_history'][$pending['id']] = $state['catalog_pending'][$kind];
                    $state['catalog_pending'][$kind]['id'] = (string) Str::uuid();
                    $state['catalog_pending'][$kind]['upload'] = null;
                    $this->store->save($state);
                }
                throw $error;
            }
        });
    }

    private function upload(array &$state, ReplayJournal $journal, string $kind): array
    {
        $pending = &$state['catalog_pending'][$kind];
        $id = $pending['id'];
        $items = array_map(fn ($item) => (object) $item, $pending['items']);
        foreach ($items as $item) {
            $this->client->codec()->validate('CatalogEntry', $item);
        }
        $upload = $journal->mutate($state, 'catalog-begin:'.$id, 'V4-24', [], (object) [
            'kind' => $kind, 'native_revision' => $pending['native_revision'], 'total_items' => count($items)]);
        $this->uploadIdentity($upload, $kind, $pending, 0, 0);
        $pending['upload'] = $this->client->codec()->encodeJson($upload);
        $this->store->save($state);
        $received = 0;
        foreach ($this->pages($items) as $page => $batch) {
            $response = $journal->mutate($state, 'catalog-page:'.$id.':'.$page, 'V4-25', ['upload_id' => $upload->upload_id],
                (object) ['page' => $page, 'items' => $batch]);
            $received += count($batch);
            $this->uploadIdentity($response, $kind, $pending, $received, $page + 1);
            if ($response->upload_id !== $upload->upload_id) {
                throw new CmsException('catalog_page_conflict');
            }
            $pending['upload'] = $this->client->codec()->encodeJson($response);
            $this->store->save($state);
        }
        $finalized = $journal->mutate($state, 'catalog-finalize:'.$id, 'V4-26', ['upload_id' => $upload->upload_id],
            (object) ['upload_id' => $upload->upload_id, 'expected_total_items' => count($items)]);
        $actual = [];
        $cursors = [];
        $repinned = false;
        $page = $finalized;
        while (true) {
            if ($page->kind !== $kind || $page->catalog_revision !== $finalized->catalog_revision
                || $page->native_revision !== $pending['native_revision'] || $page->total_items !== count($items)) {
                throw new CmsException('catalog_page_conflict');
            }
            foreach ($page->items as $item) {
                if (isset($actual[$item->id])) {
                    throw new CmsException('catalog_page_conflict');
                }
                $actual[$item->id] = $item;
            }
            if (count($actual) > count($items) || $page->next_cursor !== null && $page->items === []) {
                throw new CmsException('catalog_page_conflict');
            }
            if ($page->next_cursor === null) {
                break;
            }
            if (isset($cursors[$page->next_cursor])) {
                throw new CmsException('catalog_page_conflict');
            }
            $cursors[$page->next_cursor] = true;
            try {
                $page = $this->client->request('V4-27', [], (object) ['kind' => $kind, 'cursor' => $page->next_cursor]);
            } catch (CmsException $error) {
                if ($error->failureCode !== 'cursor_expired') {
                    throw $error;
                }
                // Re-pin current head once. It must still be the exact finalized
                // revision; never splice snapshots or activate changed data.
                $page = $this->client->request('V4-27', [], (object) ['kind' => $kind]);
                $actual = [];
                if ($repinned) {
                    throw $error;
                }
                $repinned = true;
                $cursors = [];
            }
        }
        ksort($actual, SORT_STRING);
        if (array_values($actual) != $items) {
            throw new CmsException('catalog_page_conflict');
        }
        $active = ['catalog_revision' => $finalized->catalog_revision, 'native_revision' => $pending['native_revision'],
            'total_items' => count($items), 'items' => $pending['items']];
        $state['catalog_active'][$kind] = $active;
        $state['catalog_history'][$id] = $pending;
        unset($state['catalog_pending'][$kind]);
        $this->store->save($state);
        return array_diff_key($active, ['items' => true]);
    }

    private function pages(array $items): array
    {
        $pages = [];
        $batch = [];
        foreach ($items as $item) {
            $next = [...$batch, $item];
            $body = (object) ['mutation_id' => '00000000-0000-4000-8000-000000000000', 'page' => count($pages), 'items' => $next];
            if (count($next) > 100 || strlen($this->client->codec()->encodeJson($body)) > 65536) {
                if ($batch === []) {
                    throw new CmsException('metadata_unavailable');
                }
                $pages[] = $batch;
                $batch = [$item];
            } else {
                $batch = $next;
            }
        }
        if ($batch !== []) {
            $pages[] = $batch;
        }
        return $pages;
    }

    private function uploadIdentity(stdClass $upload, string $kind, array $pending, int $received, int $page): void
    {
        if ($upload->kind !== $kind || $upload->native_revision !== $pending['native_revision']
            || $upload->total_items !== count($pending['items']) || $upload->received_items !== $received || $upload->next_page !== $page) {
            throw new CmsException('catalog_page_conflict');
        }
    }
}
