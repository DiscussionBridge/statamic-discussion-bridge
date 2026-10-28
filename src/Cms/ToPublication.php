<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

use CodeWorksLabs\DiscussionBridgeStatamic\Delivery\SourcePublication;
use DiscussionBridge\CmsV4\Codec;
use DiscussionBridge\CmsV4\ValidationException;
use stdClass;

/** One V4 full/generated representation from the complete prepared native source. */
class ToPublication
{
    public function record(array $publication, object $row, stdClass $connection, ?string $previous, Codec $codec): stdClass
    {
        $source = $publication['record'];
        $metadata = $source['source_publication'];
        $complete = $publication['full_html'];
        $record = (object) ['direction' => 'to_discourse', 'external_id' => $row->external_id,
            'canonical_url' => $source['canonical_url'], 'title' => $source['title'], 'content_html' => $complete,
            'published' => true, 'source_revision' => $metadata['revision'], 'expected_source_revision' => $previous,
            'source_created_at' => ToSourceTimestamp::instant($metadata['created_at']),
            'source_updated_at' => ToSourceTimestamp::instant($metadata['updated_at']),
            'representation' => (object) ['kind' => 'full'],
            'source_content_sha256' => $metadata['content_sha256'], 'source_content_bytes' => $metadata['content_bytes'],
            'source_authors' => array_map(fn ($author) => (object) ['id' => $author['id'], 'name' => $author['name']], $source['source_authors']),
            'primary_source_author_id' => $source['primary_source_author_id'],
            'lane' => $connection->publication_lane, 'visibility' => 'unlisted',
            'adapter_id' => (string) config('discussionbridge.adapter_id'), 'adapter_version' => (string) config('discussionbridge.adapter_version'),
            'correlation_id' => $row->correlation_id];
        $measurement = $codec->measureToFullEnvelope($record, $complete);
        if (strlen($complete) <= 49152 && $measurement <= 65536) {
            return $codec->validateGeneratedEnvelope($record);
        }
        $maximum = 49152 - strlen($codec->generatedReadMore($record->canonical_url));
        while ($maximum > 7) {
            try {
                return $codec->createGeneratedExcerpt($record, $complete, SourcePublication::excerptArticle($complete, $maximum));
            } catch (ValidationException $error) {
                if ($error->validationCode !== 'body_too_large') {
                    throw $error;
                }
                $maximum = intdiv($maximum, 2);
            }
        }
        throw new CmsException('body_too_large');
    }
}