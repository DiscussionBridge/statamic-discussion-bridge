<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Presentation;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

class HtmlSanitizer
{
    private const ALLOWED_TAGS = ['p', 'br', 'strong', 'em', 'ul', 'ol', 'li', 'blockquote', 'code', 'pre', 'h2', 'h3', 'h4', 'a'];

    public function sanitize(string $html): string
    {
        if (strlen($html) > 65536) {
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

        $this->clean($root);
        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $document->saveHTML($child);
        }

        return $result;
    }

    private function clean(DOMNode $parent): void
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
            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math'], true)) {
                    $parent->removeChild($child);
                    continue;
                }
                while ($child->firstChild) {
                    $parent->insertBefore($child->firstChild, $child);
                }
                $parent->removeChild($child);
                continue;
            }

            foreach (iterator_to_array($child->attributes) as $attribute) {
                if ($tag !== 'a' || ! in_array(strtolower($attribute->name), ['href', 'title'], true)) {
                    $child->removeAttributeNode($attribute);
                }
            }
            if ($tag === 'a') {
                $href = $child->getAttribute('href');
                if (! $this->safeHref($href)) {
                    $child->removeAttribute('href');
                }
                $child->setAttribute('rel', 'nofollow noopener noreferrer');
            }

            $this->clean($child);
        }
    }

    private function safeHref(string $href): bool
    {
        if ($href === '' || strlen($href) > 2048) {
            return false;
        }
        $parts = parse_url($href);

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https' && ! empty($parts['host']) && ! isset($parts['user'], $parts['pass']);
    }
}
