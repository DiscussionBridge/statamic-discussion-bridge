<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

use Carbon\CarbonImmutable;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;

/** Source-owned native SSG commands/artifacts; never a From Work transaction. */
class ToSourceStatic
{
    public function __construct(private readonly PrivateState $store, private readonly CmsClient $client,
        private readonly Configuration $configuration, private readonly ToSourceSnapshot $snapshots,
        private readonly ToSourceArticleProfile $profile, private readonly NativeStaticBuild $build,
        private readonly LocalStaticHooks $hooks, private readonly StaticArtifact $artifacts) {}

    public function before(array &$state, array &$move, $entry): void
    {
        $this->build->configured();
        $this->hooks->configured();
        $directory = config('discussionbridge.cms_source_static_public_directory');
        if (! is_string($directory) || $directory === '') {
            throw new CmsException('native_source_deployment_unbound');
        }
        $directory = $this->artifacts->directory($directory);
        $private = dirname($this->store->path('state.json'));
        if ($this->artifacts->within($directory, $private) || $this->artifacts->within($private, $directory)) {
            throw new CmsException('native_ownership_conflict');
        }
        $oldProjection = $this->profile->read((string) $entry->id(), $entry->locale());
        $expected = $oldProjection !== null && $oldProjection['snapshot'] === $move['native_before']
            ? $this->profile->expectedFrame($oldProjection) : $this->profile->renderPage($entry);
        if ($oldProjection === null || $oldProjection['snapshot'] !== $move['native_before']) {
            $this->profile->assertWholePlainArticle($expected, $move['source']['full_html']);
        }
        $previous = $this->artifacts->capture($directory, 'source-prior-'.$move['capture_id']);
        $path = $this->pagePath($previous, $entry);
        if (! is_file($path) || filesize($path) > strlen($expected) + 2 * 1024 * 1024
            || substr_count(file_get_contents($path), $expected) !== 1) {
            throw new CmsException('native_source_previous_artifact_unproven');
        }
        $move['static_previous'] = ['artifact' => $previous, 'article' => $expected,
            'page_relative' => str_replace('\\', '/', substr($path, strlen($previous['directory']) + 1)),
            'canonical_url' => $move['native_before']['canonical_url'], 'page_sha256' => hash_file('sha256', $path)];
    }

    public function publish(array &$state, object $row, array &$move, array $projection): string
    {
        $id = $move['capture_id'];
        if (! isset($state['source_static'][$id])) {
            if (! isset($move['static_previous'])) {
                throw new CmsException('native_source_previous_artifact_unproven');
            }
            $prepared = $this->client->codec()->parseJson($move['prepared']);
            $state['source_static'][$id] = ['owner' => 'to_source_move', 'move_id' => $prepared->move_id,
                'prepared_at' => $prepared->prepared_at, 'previous_artifact' => $move['static_previous']['artifact']];
            $this->store->save($state);
        }
        $transaction = &$state['source_static'][$id];
        if (($transaction['owner'] ?? null) !== 'to_source_move' || ($transaction['public_artifact_restored'] ?? false)) {
            throw new CmsException('native_source_move_requires_reconciliation');
        }
        $persist = function () use (&$state) { $this->store->save($state); };
        $progress = function () use (&$move, $row) {
            $connection = $this->client->request('V4-01');
            if ($connection->connection_id !== $move['scope']['connection_id']
                || $connection->scope_revision !== $move['scope']['scope_revision']
                || $connection->profile !== $move['scope']['profile']
                || $connection->publication_lane !== $move['scope']['publication_lane']
                || ! in_array('to_discourse', $connection->directions, true)
                || $connection->destination_origin !== $this->configuration->siteOrigin()
                || $this->snapshots->read($row, $move['new_path'])['snapshot'] !== $move['native_after']) {
                throw new CmsException('native_ownership_conflict');
            }
        };
        $progress();
        if (! isset($transaction['artifact'])) {
            if (isset($transaction['build'])) {
                // No command completion/immutable capture means no blind rerun.
                throw new CmsException('native_source_build_requires_reconciliation');
            }
            $this->build->generateFor('source-'.$id, $transaction, $persist, $progress);
        }
        $this->artifacts->assertCapture($transaction['artifact']);
        $actual = $this->snapshots->read($row, $move['new_path']);
        $path = $this->pagePath($transaction['artifact'], $actual['entry']);
        if (! is_file($path) || filesize($path) > strlen($this->profile->expectedFrame($projection)) + 2 * 1024 * 1024) {
            throw new CmsException('public_verification_failed');
        }
        $stream = fopen($path, 'rb');
        try {
            $this->profile->compare($stream, $projection);
        } finally {
            fclose($stream);
        }
        // Full native generation must not retain an obsolete generated old page.
        $oldPath = $transaction['artifact']['directory'].'/'.$move['static_previous']['page_relative'];
        if (! $this->artifacts->within($oldPath, $transaction['artifact']['directory'])) {
            throw new CmsException('native_ownership_conflict');
        }
        if ($oldPath !== $path && file_exists($oldPath)) {
            throw new CmsException('native_source_stale_artifact');
        }
        $progress();
        $deployment = $this->hooks->deployFor('source-'.$id, $transaction, $persist, $progress);
        $transaction['deployment'] = $deployment;
        $persist();
        $progress();
        return $deployment['deployed_at'];
    }

    /** A confirmed prior public TREE restore is not an unapplied native URL move. */
    public function restoreArtifact(array &$state, object $row, array &$move): void
    {
        $id = $move['capture_id'];
        $transaction = &$state['source_static'][$id];
        if (! isset($transaction['deployment'])) {
            return; // Uncertain deploying owner cannot safely be raced by restore.
        }
        $persist = function () use (&$state) { $this->store->save($state); };
        $guard = function () use ($row, &$move) {
            if ($this->snapshots->read($row, $move['new_path'])['snapshot'] !== $move['native_after']) {
                throw new CmsException('native_ownership_conflict');
            }
        };
        $guard();
        $restored = $this->hooks->restoreFor('source-'.$id, $transaction, $persist, $guard);
        $directory = $this->artifacts->directory(config('discussionbridge.cms_source_static_public_directory'));
        if ($this->artifacts->inspect($directory)['files'] !== $transaction['previous_artifact']['files']) {
            throw new CmsException('native_restore_required');
        }
        $transaction['restoration'] = $restored;
        $transaction['public_artifact_restored'] = true;
        $transaction['native_change_applied'] = true;
        $transaction['public_restore_verification'] = app(ToSourcePublicVerifier::class)->verifyRestored(
            $move['static_previous']['canonical_url'], $move['static_previous']['article'], $restored['deployed_at']);
        $persist();
    }

    private function pagePath(array $capture, $entry): string
    {
        $configuration = config('statamic.ssg');
        $configuration['destination'] = $capture['directory'];
        $page = new \Statamic\StaticSite\Page(app(\Illuminate\Filesystem\Filesystem::class), $configuration, $entry);
        $path = $page->path();
        if (! $this->artifacts->within($path, $capture['directory']) || is_link($path)) {
            throw new CmsException('native_ownership_conflict');
        }
        return $path;
    }
}
