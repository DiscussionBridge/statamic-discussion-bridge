<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Delivery;

use CodeWorksLabs\DiscussionBridgeStatamic\Cms\NativePreparationLimitation;
use RuntimeException;
use Statamic\Contracts\Entries\Entry;

class PublishedContent
{
    public const MAX_HTML_BYTES = 48 * 1024;

    public static function fromEntry(Entry $entry): string
    {
        $html = self::portableFromEntry($entry);
        if (strlen($html) > self::MAX_HTML_BYTES) {
            throw new RuntimeException('Statamic published content is invalid.');
        }

        return $html;
    }

    public static function portableFromEntry(Entry $entry): string
    {
        $base = app(\CodeWorksLabs\DiscussionBridgeStatamic\Cms\ToSourceArticleProfile::class)->baseFor($entry);
        return self::captureEntry($entry, $base ?? $entry->absoluteUrl())['html'];
    }

    /** The existing content field and its native article URL are captured together. */
    public static function captureEntry(Entry $entry, string $articleUrl): array
    {
        $source = $entry->get('content');
        if (! is_string($source) && ! is_array($source)) {
            throw new NativePreparationLimitation('native_content_field_not_supported');
        }
        if (is_string($source) && trim($source) === '') {
            // Phil has not selected the actual true-absence algorithm. Do not
            // interpret that unresolved preparation case as source ineligibility.
            throw new NativePreparationLimitation('source_absence_rule_unselected');
        }

        $bytes = self::originalBytes($source);
        if (! mb_check_encoding($bytes, 'UTF-8')) {
            throw new NativePreparationLimitation('native_content_value_not_representable');
        }
        $original = ['article_url' => $articleUrl, 'content_base64' => base64_encode($bytes),
            'content_bytes' => strlen($bytes), 'content_sha256' => hash('sha256', $bytes)];
        if (is_array($source)) {
            $original['content_encoding'] = 'native_json';
        }
        $rendered = app(NativeContentRenderer::class)->render($entry, $source);
        $original['field_type'] = $rendered['field_type'];
        if ($rendered['html'] === '') {
            throw new NativePreparationLimitation('native_article_empty_presentation_unresolved');
        }
        if (! mb_check_encoding($rendered['html'], 'UTF-8')
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $rendered['html'])) {
            throw new NativePreparationLimitation('native_rendered_bytes_not_supported');
        }
        $html = CapturedArticleReferences::resolveHtml($rendered['html'], $articleUrl);
        if (! mb_check_encoding($html, 'UTF-8')
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $html)) {
            throw new NativePreparationLimitation('native_rendered_bytes_not_supported');
        }

        return ['original' => $original, 'html' => $html];
    }

    /** Private exact native-value capture; string history keeps its original bytes. */
    public static function originalBytes(mixed $source): string
    {
        if (is_string($source)) {
            return $source;
        }
        if (! is_array($source)) {
            throw new NativePreparationLimitation('native_content_field_not_supported');
        }
        try {
            return json_encode($source, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            throw new NativePreparationLimitation('native_content_value_not_representable');
        }
    }
}
