<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Presentation;

use DOMDocument;
use DOMElement;
use DOMXPath;

class PageNavigation
{
    public function render(string $html): string
    {
        if (trim($html) === '') {
            return $html;
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="discussionbridge-page-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return $html;
        }

        $xpath = new DOMXPath($document);
        $headings = $xpath->query('//*[@id="discussionbridge-page-root"]//*[self::h2 or self::h3]');
        if ($headings === false || $headings->length < 2) {
            return $html;
        }

        $used = [];
        $links = [];
        foreach ($headings as $heading) {
            $label = trim((string) $heading->textContent);
            if ($label === '') {
                continue;
            }
            $base = $this->slug($heading->getAttribute('id') ?: $label) ?: 'section';
            $id = $base;
            $suffix = 2;
            while (isset($used[$id])) {
                $id = $base.'-'.$suffix++;
            }
            $used[$id] = true;
            $heading->setAttribute('id', $id);
            $links[] = sprintf(
                '<li class="discussionbridge-toc__level-%s"><a href="#%s">%s</a></li>',
                strtolower($heading->nodeName),
                htmlspecialchars($id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            );
        }
        if (count($links) < 2) {
            return $html;
        }

        $root = $document->getElementById('discussionbridge-page-root');
        if (! $root instanceof DOMElement) {
            return $html;
        }
        $content = '';
        foreach ($root->childNodes as $child) {
            $content .= $document->saveHTML($child);
        }

        return '<nav class="discussionbridge-toc" aria-label="On this page"><strong>On this page</strong><ol>'.implode('', $links).'</ol></nav>'.$content;
    }

    private function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';

        return trim($value, '-');
    }
}
