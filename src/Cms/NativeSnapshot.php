<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

class NativeSnapshot
{
    public static function entryDigest($entry): string
    {
        $attributes = [...$entry->getCurrentDirtyStateAttributes(), 'blueprint' => $entry->blueprint()->handle()];
        unset($attributes['discussionbridge_cms_native_version'], $attributes['discussionbridge_cms_native_digest']);
        return self::digest($attributes);
    }

    public static function digest(array $attributes): string
    {
        // The native Eloquent driver omits null data values and both native
        // dirty snapshots omit updated_at. Keep source null as unknown at read.
        $attributes = array_filter($attributes, fn ($value) => $value !== null);
        return hash('sha256', json_encode(self::ordered($attributes), JSON_THROW_ON_ERROR));
    }

    private static function ordered(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        return array_map(self::ordered(...), $value);
    }
}
