<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Support;

use CodeWorksLabs\DiscussionBridgeStatamic\ServiceProvider;
use Illuminate\Support\ServiceProvider as LaravelServiceProvider;
use RuntimeException;

class PresentationAssets
{
    public const RICH_TAG = 'statamic-discussion-bridge-rich-content';
    public const TEMPLATES_TAG = 'statamic-discussion-bridge-templates';

    /** @return array<string, string> */
    public static function richPaths(): array
    {
        return [
            self::richSource() => public_path('discussionbridge/rich-content.js'),
            dirname(__DIR__, 2).'/resources/dist/discussionbridge-rich-content.css' => public_path('discussionbridge/rich-content.css'),
        ];
    }

    /** @return array<string, string> */
    public static function templatePaths(): array
    {
        $root = dirname(__DIR__, 2);

        return [
            $root.'/starter-kit/entry.antlers.html' => resource_path('views/vendor/discussionbridge/entry.antlers.html'),
            $root.'/starter-kit/entry-ssg.antlers.html' => resource_path('views/vendor/discussionbridge/entry-ssg.antlers.html'),
        ];
    }

    public static function selected(): bool
    {
        return config('discussionbridge.rich_content', false) === true;
    }

    public static function verifyRich(): void
    {
        $paths = LaravelServiceProvider::pathsToPublish(ServiceProvider::class, self::RICH_TAG);
        foreach (self::richPaths() as $source => $expected) {
            $target = $paths[$source] ?? null;
            if (! is_string($target) || ! is_file($source) || ! is_file($target) || is_link($target)
                || hash_file('sha256', $source) !== hash_file('sha256', $target)) {
                throw new RuntimeException('Selected DiscussionBridge rich-content asset is unavailable or outdated. Preserve operator changes; explicitly refresh with vendor:publish --tag='.self::RICH_TAG.' --force, or disable DISCUSSIONBRIDGE_RICH_CONTENT and use your own renderer.');
            }
        }
    }

    public static function markup(): string
    {
        if (! self::selected()) {
            return '';
        }

        $fingerprint = substr((string) hash_file('sha256', self::richSource()), 0, 16);
        $cssFingerprint = substr((string) hash_file('sha256', dirname(__DIR__, 2).'/resources/dist/discussionbridge-rich-content.css'), 0, 16);

        return '<link rel="stylesheet" href="/discussionbridge/rich-content.css?v='.$cssFingerprint.'"><script src="/discussionbridge/rich-content.js?v='.$fingerprint.'" defer></script>';
    }

    public static function nativeMarkup(string $priorHtml): string
    {
        return self::markup();
    }

    private static function richSource(): string
    {
        return dirname(__DIR__, 2).'/resources/dist/discussionbridge-rich-content.js';
    }
}
