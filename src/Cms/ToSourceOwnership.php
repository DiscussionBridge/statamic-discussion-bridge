<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

/** Component-private source receipt. Deliberately not a From mapping receipt. */
class ToSourceOwnership
{
    public const FORMAT = 'statamic-to-source-ownership-1';
    public const FIELDS = ['format', 'connection_id', 'forum_origin', 'native_origin', 'publication_lane',
        'entry_id', 'site_handle', 'collection_handle', 'external_id', 'resource_id', 'topic_id',
        'current_source_revision', 'canonical_url', 'complete_prepared_sha256', 'complete_prepared_bytes',
        'title_sha256', 'authors_sha256', 'primary_author_id', 'source_created_at', 'source_updated_at',
        'storage_kind', 'actual_storage_snapshot_sha256', 'actual_route_snapshot_sha256',
        'source_render_inputs_sha256', 'move_id', 'issued_public_nonce'];

    public static function sha256(array $receipt): string
    {
        if (array_keys($receipt) !== self::FIELDS || $receipt['format'] !== self::FORMAT) {
            throw new CmsException('native_restore_required');
        }
        $bytes = self::FORMAT."\0";
        foreach ($receipt as $value) {
            if ($value === null) {
                $bytes .= pack('N', 0xffffffff);
            } elseif (is_string($value) && mb_check_encoding($value, 'UTF-8')) {
                $bytes .= pack('N', strlen($value)).$value;
            } else {
                throw new CmsException('native_restore_required');
            }
        }
        return hash('sha256', $bytes);
    }
}
