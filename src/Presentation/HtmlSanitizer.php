<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Presentation;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\ContentPreservationLimitation;

class HtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'em', 'ul', 'ol', 'li', 'blockquote', 'code', 'pre',
        'h1', 'h2', 'h3', 'h4', 'a', 'img', 'figure', 'figcaption', 'table', 'thead',
        'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'colgroup', 'col', 'div', 'span', 'hr', 'aside', 'time', 'details', 'summary',
    ];

    public function sanitize(string $html, ?int $maximumBytes = 65536, ?string $sourceUrl = null): string
    {
        if (($maximumBytes !== null && strlen($html) > $maximumBytes) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $html)) {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8"><div id="discussionbridge-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return '';
        }

        $root = (new DOMXPath($document))->query('//*[@id="discussionbridge-root"]')->item(0);
        if (! $root instanceof DOMElement) {
            return '';
        }

        $this->clean($root, $sourceUrl);
        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $document->saveHTML($child);
        }

        return $result;
    }

    /** Preserve a complete imported article, or require attention before native mutation. */
    public function sanitizeArticle(string $html, string $sourceUrl): string
    {
        if (! $this->safeHref($sourceUrl)) {
            throw new ContentPreservationLimitation('source_reference_unrepresented');
        }
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            throw new ContentPreservationLimitation('native_article_parse_unrepresented');
        }
        $xpath = new DOMXPath($document);
        // These are actual parsed elements, not text containing code/tag names.
        // No arbitrary source execution/provider binding exists in this path.
        foreach ($xpath->query('//*') as $node) {
            $tag = strtolower($node->tagName);
            $mediaFunction = match ($tag) {
                'video' => 'Video playback and player controls have no supported native article binding.',
                'audio' => 'Audio playback and player controls have no supported native article binding.',
                'iframe', 'object', 'embed' => 'Embedded player or application interaction has no supported native article binding.',
                'source' => 'Media source selection is not represented by the native article conversion.',
                'track' => 'Timed media captions are not represented by the native article conversion.',
                'svg' => 'Native vector content is not represented by the current article conversion.',
                'math' => 'Structured mathematical markup is not represented by the current article conversion.',
                default => null,
            };
            if ($mediaFunction !== null) {
                // Keep the existing cause identity: richer diagnostics must not
                // make the same held Work consume another claim after upgrade.
                throw new ContentPreservationLimitation('native_media_unrepresented', $mediaFunction);
            }
            if ($tag === 'img' && (trim($node->getAttribute('srcset')) !== '' || trim($node->getAttribute('sizes')) !== '')) {
                throw new ContentPreservationLimitation('native_media_unrepresented',
                    'Responsive image source selection has no supported native article binding; removing srcset or sizes changes that presentation.');
            }
        }
        // Retain the existing all-media-first cause order. Adding a diagnostic
        // must not invalidate a still-current durable media refusal.
        foreach ($xpath->query('//*') as $node) {
            $tag = strtolower($node->tagName);
            if ($tag === 'style' && trim($node->textContent) !== '') {
                throw new ContentPreservationLimitation('native_active_function_unrepresented',
                    'Source stylesheet presentation or generated content has no supported native article binding; removing it may lose article meaning.');
            }
            if ($this->insideTemplate($node)) {
                if ($tag === 'script' && $node->textContent !== '') {
                    $this->dataLoss($node->textContent);
                }
                continue;
            }
            $scriptFunction = $tag === 'script' ? $this->scriptFunction($node) : null;
            if ($scriptFunction !== null) {
                throw new ContentPreservationLimitation('native_active_function_unrepresented',
                    $scriptFunction.' has no supported native article component; removing it loses that behavior.');
            }
            if ($tag === 'script' && $node->textContent !== '') {
                // Actual nonempty inert data would be removed by clean(). Do not
                // call it execution, or convert it into invented display code.
                $this->dataLoss($node->textContent);
            }
            if ($tag === 'form' || in_array($tag, ['textarea', 'select'], true)
                || ($tag === 'input' && strtolower($node->getAttribute('type')) !== 'hidden')
                || ($tag === 'button' && $node->hasAttribute('form'))) {
                throw new ContentPreservationLimitation('native_active_function_unrepresented',
                    'Native '.$tag.' submission or control interaction is not preserved by the current article conversion.');
            }
            foreach ($node->attributes as $attribute) {
                $name = strtolower($attribute->name);
                if (preg_match('/\Aon[a-z][a-z0-9]*\z/', $name) === 1 && trim($attribute->value) !== '') {
                    throw new ContentPreservationLimitation('native_active_function_unrepresented',
                        'Source '.$name.' interaction has no supported native article binding; removing the handler loses its behavior.');
                }
                if ($name === 'style' && trim($attribute->value) !== '') {
                    throw new ContentPreservationLimitation('native_active_function_unrepresented',
                        'Source inline style presentation has no supported native article binding; removing it may lose article meaning.');
                }
            }
        }
        foreach ($xpath->query('//a[@href]|//img') as $node) {
            $attribute = strtolower($node->tagName) === 'img' ? 'src' : 'href';
            $value = $this->sourceReference($node->getAttribute($attribute), $sourceUrl);
            if ($value === null || ! $this->safeHref($value)) {
                throw new ContentPreservationLimitation('source_reference_unrepresented');
            }
        }
        return $this->sanitize($html, null, $sourceUrl);
    }

    private function clean(DOMNode $parent, ?string $sourceUrl): void
    {
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if ($child instanceof DOMComment) {
                $parent->removeChild($child);
                continue;
            }
            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);
            if (($tag === 'p' && trim((string) $child->textContent) === '[discotoc]')
                || ($tag === 'div' && $child->getAttribute('data-theme-toc') === 'true')
                || ($tag === 'div' && $this->hasClass($child, 'meta') && $this->hasAncestorClass($child, 'lightbox-wrapper'))) {
                $parent->removeChild($child);
                continue;
            }
            if ($tag === 'a' && $this->hasClass($child, 'anchor') && trim((string) $child->textContent) === '') {
                $parent->removeChild($child);
                continue;
            }
            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math'], true)) {
                    $parent->removeChild($child);
                    continue;
                }
                // Descendants remain untrusted even when their wrapper is unwrapped.
                $this->clean($child, $sourceUrl);
                while ($child->firstChild) {
                    $parent->insertBefore($child->firstChild, $child);
                }
                $parent->removeChild($child);
                continue;
            }

            $allowedAttributes = match ($tag) {
                'a' => ['href', 'title'],
                'img' => ['src', 'alt', 'title', 'width', 'height'],
                'code', 'pre', 'div', 'span', 'aside' => ['class'],
                'time' => ['datetime'],
                'td' => ['colspan', 'rowspan'],
                'th' => ['colspan', 'rowspan', 'scope'],
                'col', 'colgroup' => ['span'],
                'ol' => ['start', 'reversed', 'type'],
                'li' => ['value'],
                'details' => ['open', 'name'],
                default => [],
            };
            foreach (iterator_to_array($child->attributes) as $attribute) {
                if (! in_array(strtolower($attribute->name), $allowedAttributes, true)
                    || ! $this->validPassiveAttribute(strtolower($attribute->name), $attribute->value)) {
                    $child->removeAttributeNode($attribute);
                }
            }
            if ($tag === 'a') {
                $href = $this->sourceReference($child->getAttribute('href'), $sourceUrl);
                if ($href === null || ! $this->safeHref($href)) {
                    $child->removeAttribute('href');
                } else {
                    $child->setAttribute('href', $href);                }
                $child->setAttribute('rel', 'nofollow noopener noreferrer');
            }
            if ($tag === 'img') {
                $src = $this->sourceReference($child->getAttribute('src'), $sourceUrl);
                if ($src === null || ! $this->safeHref($src)) {
                    $parent->removeChild($child);
                    continue;
                }
                $child->setAttribute('src', $src);
            }

            $this->clean($child, $sourceUrl);
        }
    }

    private function dataLoss(string $data): never
    {
        throw new ContentPreservationLimitation('native_data_block_unrepresented', null,
            'Inert source data ('.strlen($data).' UTF-8 bytes; SHA-256 '.hash('sha256', $data)
            .') would be removed by the current native article conversion.');
    }

    private function insideTemplate(DOMElement $element): bool
    {
        for ($parent = $element->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode) {
            if (in_array(strtolower($parent->tagName), ['template', 'noscript'], true)) {
                return true;
            }
        }
        return false;
    }

    /** HTML script type semantics, not a permission to execute source scripts.
     * https://html.spec.whatwg.org/multipage/scripting.html#prepare-the-script-element
     * https://mimesniff.spec.whatwg.org/#javascript-mime-type
     */
    private function scriptFunction(DOMElement $script): ?string
    {
        if ((! $script->hasAttribute('src') || $script->getAttribute('src') === '') && $script->textContent === '') {
            return null;
        }
        $type = $script->hasAttribute('type') ? $script->getAttribute('type')
            : ($script->getAttribute('language') === '' ? '' : 'text/'.$script->getAttribute('language'));
        $special = match (strtolower($type)) {
            'module' => 'Source JavaScript module initialization',
            'importmap' => 'Source module import resolution',
            'speculationrules' => 'Source speculative navigation rules',
            default => null,
        };
        if ($special !== null) {
            return $special;
        }
        $essence = $script->hasAttribute('type') ? trim($type, "\x09\x0A\x0C\x0D ") : $type;
        return $type === '' || in_array(strtolower($essence), [
            'application/ecmascript', 'application/javascript', 'application/x-ecmascript', 'application/x-javascript',
            'text/ecmascript', 'text/javascript', 'text/javascript1.0', 'text/javascript1.1', 'text/javascript1.2',
            'text/javascript1.3', 'text/javascript1.4', 'text/javascript1.5', 'text/jscript', 'text/livescript',
            'text/x-ecmascript', 'text/x-javascript',
        ], true) ? 'Source script initialization' : null;
    }

    private function hasClass(DOMElement $element, string $class): bool
    {
        return in_array($class, preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [], true);
    }

    private function hasAncestorClass(DOMElement $element, string $class): bool
    {
        $parent = $element->parentNode;
        while ($parent instanceof DOMElement) {
            if ($this->hasClass($parent, $class)) {
                return true;
            }
            $parent = $parent->parentNode;
        }

        return false;
    }

    private function validPassiveAttribute(string $name, string $value): bool
    {
        return match ($name) {
            'colspan', 'span' => $this->span($value, 1, 1000),
            'rowspan' => $this->span($value, 0, 65534),
            'start', 'value' => preg_match('/\A-?[0-9]+\z/', $value) === 1,
            'type' => in_array($value, ['1', 'a', 'A', 'i', 'I'], true),
            'scope' => in_array($value, ['row', 'col', 'rowgroup', 'colgroup'], true),
            default => true,
        };
    }

    private function span(string $value, int $minimum, int $maximum): bool
    {
        if (preg_match('/\A[0-9]+\z/', $value) !== 1) {
            return false;
        }
        $digits = ltrim($value, '0');
        $digits = $digits === '' ? '0' : $digits;
        return strlen($digits) <= strlen((string) $maximum)
            && (int) $digits >= $minimum && (int) $digits <= $maximum;
    }

    private function sourceReference(string $value, ?string $sourceUrl): ?string
    {
        if ($value === '' || trim($value) !== $value || strlen($value) > 2048
            || str_contains($value, '\\') || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            return null;
        }
        if ($sourceUrl === null || parse_url($value, PHP_URL_SCHEME) !== null) {
            return $value;
        }
        try {
            return (string) UriResolver::resolve(new Uri($sourceUrl), new Uri($value));
        } catch (\Throwable) {
            return null;
        }
    }

    private function safeHref(string $href): bool
    {
        if ($href === '' || trim($href) !== $href || strlen($href) > 2048 || str_contains($href, '\\') || preg_match('/[\x00-\x1F\x7F]/', $href)) {
            return false;
        }
        $parts = parse_url($href);

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https' && ! empty($parts['host']) && ! isset($parts['user']) && ! isset($parts['pass']);
    }
}
