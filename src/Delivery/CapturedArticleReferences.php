<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Delivery;

use CodeWorksLabs\DiscussionBridgeStatamic\Cms\NativePreparationLimitation;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use DOMDocument;
use DOMElement;
use DOMXPath;

/** Resolve the already-rendered native copy; grants no URI/provider permission. */
class CapturedArticleReferences
{
    public static function document(string $html): array
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML('<?xml encoding="UTF-8"><html><body><div id="discussionbridge-native-copy">'
                .$html.'</div></body></html>', LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($document);
        $roots = $xpath->query('//*[@id="discussionbridge-native-copy"]');
        $root = $roots->item(0);
        $body = $document->getElementsByTagName('body')->item(0);
        if (! $loaded || $roots->length !== 1 || ! $root instanceof DOMElement || ! $body instanceof DOMElement) {
            throw new NativePreparationLimitation('native_article_parse_unrepresented');
        }
        foreach ($body->childNodes as $node) {
            if ($node !== $root && trim((string) $node->textContent) !== '') {
                throw new NativePreparationLimitation('native_article_boundary_unrepresented');
            }
            if ($node instanceof DOMElement && $node !== $root) {
                throw new NativePreparationLimitation('native_article_boundary_unrepresented');
            }
        }
        return [$document, $root];
    }

    public static function resolveHtml(string $html, string $articleUrl): string
    {
        [$document, $root] = self::document($html);
        $changed = false;
        foreach ((new DOMXPath($document))->query('.//*', $root) as $element) {
            foreach (['href', 'src', 'poster', 'cite', 'longdesc', 'action', 'formaction', 'background', 'data'] as $attribute) {
                if (! $element->hasAttribute($attribute)) {
                    continue;
                }
                $original = $element->getAttribute($attribute);
                $resolved = self::reference($original, $articleUrl);
                if ($original !== $resolved) {
                    $element->setAttribute($attribute, $resolved);
                    $changed = true;
                }
            }
            if ($element->hasAttribute('srcset')) {
                $original = $element->getAttribute('srcset');
                $resolved = self::srcset($original, $articleUrl);
                if ($original !== $resolved) {
                    $element->setAttribute('srcset', $resolved);
                    $changed = true;
                }
            }
        }
        if (! $changed) {
            return $html;
        }
        $resolved = '';
        foreach ($root->childNodes as $child) {
            $resolved .= $document->saveHTML($child);
        }
        return $resolved;
    }

    private static function reference(string $reference, string $articleUrl): string
    {
        try {
            $uri = new Uri($reference);
            return $uri->getScheme() === '' ? (string) UriResolver::resolve(new Uri($articleUrl), $uri) : $reference;
        } catch (\InvalidArgumentException) {
            throw new NativePreparationLimitation('native_relative_reference_unrepresentable');
        }
    }

    private static function srcset(string $value, string $articleUrl): string
    {
        if (preg_match('/(?:\A|[,\s])data:/i', $value)) {
            throw new NativePreparationLimitation('native_srcset_data_representation_unavailable');
        }
        $candidates = [];
        $changed = false;
        foreach (explode(',', $value) as $candidate) {
            if (! preg_match('/\A\s*(\S+)(?:\s+((?:[1-9][0-9]*w|(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)x)))?\s*\z/', $candidate, $match)) {
                throw new NativePreparationLimitation('native_srcset_representation_unavailable');
            }
            $resolved = self::reference($match[1], $articleUrl);
            $changed = $changed || $resolved !== $match[1];
            $candidates[] = $resolved.(isset($match[2]) ? ' '.$match[2] : '');
        }
        return $changed ? implode(', ', $candidates) : $value;
    }
}
