<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Delivery;

use RuntimeException;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Markdown;

class PublishedContent
{
    public const MAX_HTML_BYTES = 48 * 1024;

    public static function fromEntry(Entry $entry): string
    {
        $source = $entry->get('content');
        if (! is_string($source) || trim($source) === '') {
            throw new RuntimeException('Statamic published content is invalid.');
        }

        $html = Markdown::parse($source);
        if (trim(strip_tags($html)) === ''
            || strlen($html) > self::MAX_HTML_BYTES
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $html)) {
            throw new RuntimeException('Statamic published content is invalid.');
        }

        return $html;
    }
}
