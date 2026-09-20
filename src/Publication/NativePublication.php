<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Publication;

use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use RuntimeException;
use Statamic\Facades\Collection;
use Statamic\Facades\Term;

class NativePublication
{
    public function __construct(
        private readonly Configuration $configuration,
        private readonly PlatformCatalog $catalog,
    )
    {
    }

    public function fromRecord(array $record): ?array
    {
        $bindings = array_values(array_filter(
            is_array($record['bindings'] ?? null) ? $record['bindings'] : [],
            fn ($binding) => is_array($binding) && ($binding['role'] ?? null) === 'presentation' && ($binding['state'] ?? null) === 'active',
        ));
        if (! collect($bindings)->contains(fn ($binding) => ($binding['native_materialization'] ?? null) === true)) {
            return null;
        }
        if (count($bindings) !== 1 || ($bindings[0]['native_materialization'] ?? null) !== true) {
            throw new RuntimeException('DiscussionBridge native publication authority is ambiguous.');
        }
        if (($record['direction'] ?? null) !== 'from_discourse'
            || ($record['state'] ?? null) !== 'healthy'
            || ! is_string($record['resource_id'] ?? null)
            || ! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $record['resource_id'])
            || ! is_int($record['topic_id'] ?? null)
            || $record['topic_id'] < 1
            || ! is_string($record['content_html'] ?? null)
            || trim($record['content_html']) === ''
            || strlen($record['content_html']) > 65536) {
            throw new RuntimeException('DiscussionBridge native publication record is invalid.');
        }

        $destination = $this->exactUrl($bindings[0]['canonical_url'] ?? null, $this->configuration->siteOrigin(), 'destination');
        $migrationProof = $bindings[0]['url_migration'] ?? null;
        $urlMigration = null;
        if ($migrationProof !== null) {
            if (! is_array($migrationProof)
                || ! in_array($migrationProof['redirect_status'] ?? null, [301, 308], true)
                || ! is_string($migrationProof['verified_at'] ?? null)
                || strtotime($migrationProof['verified_at']) === false) {
                throw new RuntimeException('DiscussionBridge publication URL migration proof is invalid.');
            }
            $oldUrl = $this->exactUrl($migrationProof['old_url'] ?? null, $this->configuration->siteOrigin(), 'previous publication')['url'];
            $newUrl = $this->exactUrl($migrationProof['new_url'] ?? null, $this->configuration->siteOrigin(), 'migrated publication')['url'];
            if ($oldUrl === $newUrl || $newUrl !== $destination['url']) {
                throw new RuntimeException('DiscussionBridge publication URL migration proof is invalid.');
            }
            $urlMigration = ['old_url' => $oldUrl, 'new_url' => $newUrl];
        }
        $path = trim($destination['path'], '/');
        if (! preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*(?:\/[a-z0-9]+(?:-[a-z0-9]+)*)*\z/', $path)) {
            throw new RuntimeException('DiscussionBridge native publication path is invalid.');
        }
        $segments = explode('/', $path);
        $slug = end($segments);

        $source = $record['source'] ?? null;
        if (! is_array($source)
            || ($source['platform'] ?? null) !== 'discourse'
            || ($source['origin'] ?? null) !== $this->configuration->forumOrigin()
            || ($source['topic_id'] ?? null) !== $record['topic_id']
            || ($source['post_number'] ?? null) !== 1
            || ! is_int($source['post_id'] ?? null)
            || $source['post_id'] < 1
            || ! is_int($source['post_version'] ?? null)
            || $source['post_version'] < 1
            || ($source['revision'] ?? null) !== 'post:'.$source['post_id'].':version:'.$source['post_version']) {
            throw new RuntimeException('DiscussionBridge native publication source is invalid.');
        }
        $topicUrl = $this->exactUrl($source['topic_url'] ?? null, $this->configuration->forumOrigin(), 'topic')['url'];
        $author = $source['author'] ?? null;
        if (! is_array($author) || ! is_string($author['name'] ?? null) || trim($author['name']) === '' || strlen($author['name']) > 200) {
            throw new RuntimeException('DiscussionBridge native publication author is invalid.');
        }
        $this->exactUrl($author['profile_url'] ?? null, $this->configuration->forumOrigin(), 'author');
        if (! is_string($record['title'] ?? null) || trim($record['title']) === '' || strlen($record['title']) > 1024) {
            throw new RuntimeException('DiscussionBridge native publication title is invalid.');
        }

        return [
            'resource_id' => strtolower($record['resource_id']),
            'canonical_url' => $destination['url'],
            'url_migration' => $urlMigration,
            'path' => '/'.$path,
            'parent_uri' => count($segments) > 1 ? '/'.implode('/', array_slice($segments, 0, -1)) : null,
            'slug' => $slug,
            'title' => trim($record['title']),
            'content_html' => $record['content_html'],
            'source_revision' => $source['revision'],
            'source_author' => trim($author['name']),
            'topic_id' => $record['topic_id'],
            'topic_url' => $topicUrl,
        ];
    }

    public function fromSourceTopic(array $topic): array
    {
        $topicId = $topic['topic_id'] ?? null;
        $destination = $topic['destination'] ?? null;
        if (! is_int($topicId) || $topicId < 1
            || ! is_array($destination)
            || ($destination['state'] ?? null) !== 'ready'
            || ! is_string($destination['destination_container_id'] ?? null)
            || ! is_string($destination['mapping_revision'] ?? null)
            || ! preg_match('/\A[a-f0-9]{64}\z/', $destination['mapping_revision'])
            || ! is_string($topic['source_revision'] ?? null)
            || $topic['source_revision'] === ''
            || strlen($topic['source_revision']) > 128
            || ! is_string($topic['publication_revision'] ?? null)
            || ! preg_match('/\A[a-f0-9]{64}\z/', $topic['publication_revision'])
            || ! is_string($topic['content_html'] ?? null)
            || trim($topic['content_html']) === ''
            || strlen($topic['content_html']) > 65536) {
            throw new RuntimeException('DiscussionBridge Statamic source topic is invalid.');
        }
        $title = $topic['title'] ?? null;
        if (! is_string($title) || trim($title) === '' || strlen($title) > 1024) {
            throw new RuntimeException('DiscussionBridge Statamic source title is invalid.');
        }
        $topicUrl = $this->exactUrl($topic['topic_url'] ?? null, $this->configuration->forumOrigin(), 'topic')['url'];
        $author = $topic['author'] ?? null;
        if (! is_array($author) || ! is_string($author['name'] ?? null) || trim($author['name']) === '' || strlen($author['name']) > 200) {
            throw new RuntimeException('DiscussionBridge Statamic source author is invalid.');
        }
        $this->exactUrl($author['profile_url'] ?? null, $this->configuration->forumOrigin(), 'author');
        $createdAt = $this->isoDate($topic['source_created_at'] ?? null, 'creation');
        $updatedAt = $this->isoDate($topic['source_updated_at'] ?? null, 'update');
        if (strtotime($updatedAt) < strtotime($createdAt)) {
            throw new RuntimeException('DiscussionBridge Statamic source update precedes creation.');
        }
        $slugPolicy = $destination['slug_policy'] ?? null;
        $slug = match ($slugPolicy) {
            'topic_id' => 'forum-topic-'.$topicId,
            'source_title' => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) ?: $title)), '-'),
            default => throw new RuntimeException('DiscussionBridge Statamic slug policy is unsupported.'),
        };
        $slug = substr($slug, 0, 180);
        if ($slug === '' || ! preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug)) {
            $slug = 'forum-topic-'.$topicId;
        }
        $collectionHandle = $destination['destination_container_id'];
        $collection = Collection::findByHandle($collectionHandle);
        if (! $collection || ! $this->configuration->collectionAllowed($collectionHandle)) {
            throw new RuntimeException('DiscussionBridge Statamic destination collection is unsupported.');
        }
        $destinationAuthor = $destination['destination_author_id'] ?? null;
        if (! is_string($destinationAuthor) || ! str_starts_with($destinationAuthor, 'user:')) {
            throw new RuntimeException('DiscussionBridge Statamic destination author is invalid.');
        }
        $authorId = substr($destinationAuthor, 5);
        if ($authorId === '' || strlen($authorId) > 255) {
            throw new RuntimeException('DiscussionBridge Statamic destination author is invalid.');
        }
        $taxonomyValues = [];
        foreach (is_array($destination['destination_terms'] ?? null) ? $destination['destination_terms'] : [] as $term) {
            $taxonomyId = is_array($term) ? ($term['destination_taxonomy_id'] ?? null) : null;
            $termId = is_array($term) ? ($term['destination_term_id'] ?? null) : null;
            $resolved = is_string($termId) ? Term::find($termId) : null;
            if (! is_string($taxonomyId) || ! $resolved || $resolved->taxonomyHandle() !== $taxonomyId
                || ! $collection->taxonomies()->contains(fn ($taxonomy) => $taxonomy->handle() === $taxonomyId)) {
                throw new RuntimeException('DiscussionBridge Statamic destination taxonomy term is invalid.');
            }
            $taxonomyValues[$taxonomyId][] = $termId;
        }
        $path = $this->catalog->canonicalPath($collectionHandle, $slug);

        return [
            'resource_id' => null,
            'canonical_url' => $this->configuration->siteOrigin().$path.'/',
            'url_migration' => null,
            'path' => $path,
            'parent_uri' => null,
            'slug' => $slug,
            'collection' => $collectionHandle,
            'destination_author_id' => $authorId,
            'destination_taxonomies' => $taxonomyValues,
            'title' => trim($title),
            'content_html' => $topic['content_html'],
            'source_revision' => $topic['source_revision'],
            'publication_revision' => $topic['publication_revision'],
            'mapping_revision' => $destination['mapping_revision'],
            'destination' => $destination,
            'source_author' => trim($author['name']),
            'source_created_at' => $createdAt,
            'source_updated_at' => $updatedAt,
            'topic_id' => $topicId,
            'topic_url' => $topicUrl,
        ];
    }

    private function exactUrl(mixed $value, string $origin, string $label): array
    {
        if (! is_string($value) || trim($value) !== $value || $value === '' || strlen($value) > 2048) {
            throw new RuntimeException("DiscussionBridge {$label} URL is invalid.");
        }
        $parts = parse_url($value);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])) {
            throw new RuntimeException("DiscussionBridge {$label} URL is invalid.");
        }
        $urlOrigin = 'https://'.strtolower((string) ($parts['host'] ?? '')).(isset($parts['port']) ? ':'.$parts['port'] : '');
        if ($urlOrigin !== $origin || ! is_string($parts['path'] ?? null) || ! str_starts_with($parts['path'], '/')) {
            throw new RuntimeException("DiscussionBridge {$label} URL is invalid.");
        }

        return ['url' => $value, 'path' => $parts['path']];
    }

    private function isoDate(mixed $value, string $label): string
    {
        if (! is_string($value) || strlen($value) > 64
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?Z\z/', $value) !== 1
            || strtotime($value) === false) {
            throw new RuntimeException("DiscussionBridge Statamic source {$label} time is invalid.");
        }

        return $value;
    }
}
