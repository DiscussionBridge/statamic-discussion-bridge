<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\PageNavigation;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\PresentationAssets;

/** Complete source article profile, distinct from From OwnedFragments. */
class ToSourceArticleProfile
{
    public const PROFILE = 'statamic-antlers-source-article-1';

    public function __construct(private readonly PrivateState $store, private readonly ToSourceSnapshot $snapshots) {}

    public function filename(string $entryId, string $site): string
    {
        return 'source-render-'.hash('sha256', $site."\0".$entryId).'.json';
    }

    public function read(string $entryId, string $site): ?array
    {
        $path = $this->store->path($this->filename($entryId, $site));
        if (! file_exists($path)) {
            return null;
        }
        if (is_link($path) || ! is_file($path)) {
            throw new CmsException('native_ownership_conflict');
        }
        try {
            $envelope = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($envelope) || ! isset($envelope['projection'], $envelope['sha256'])
                || ToSourceSnapshot::digest($envelope['projection']) !== $envelope['sha256']
                || $envelope['projection']['profile'] !== self::PROFILE
                || $envelope['projection']['snapshot']['entry_id'] !== $entryId
                || $envelope['projection']['snapshot']['site_handle'] !== $site) {
                throw new CmsException('native_restore_required');
            }
            return $envelope['projection'];
        } catch (\JsonException) {
            throw new CmsException('native_restore_required');
        }
    }

    public function publish(array $projection): void
    {
        $snapshot = $projection['snapshot'];
        $this->store->write($this->filename($snapshot['entry_id'], $snapshot['site_handle']),
            json_encode(['projection' => $projection, 'sha256' => ToSourceSnapshot::digest($projection)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function baseFor($entry): ?string
    {
        $projection = $this->read((string) $entry->id(), $entry->locale());
        if ($projection === null || ! is_string($entry->get('content'))
            || hash('sha256', $entry->get('content')) !== $projection['source']['native_source']['content_sha256']) {
            return null;
        }
        return $projection['source']['native_source']['article_url'];
    }

    /** Called by the actual native source article tag; NEVER takes writer lock. */
    public function renderIfCurrent($entry): ?string
    {
        $projection = $this->read((string) $entry->id(), $entry->locale());
        if ($projection === null) {
            return null;
        }
        $row = (object) $projection['row'];
        try {
            $current = $this->snapshots->read($row);
        } catch (CmsException) {
            return null;
        }
        if ($current['snapshot'] !== $projection['snapshot']) {
            return null; // Ordinary new native content renders through existing path.
        }
        return $this->frame($projection);
    }

    public function frame(array $projection): string
    {
        $snapshot = $projection['snapshot'];
        $body = $projection['source']['full_html'];
        $evidence = $projection['source']['record']['source_publication'];
        if (hash('sha256', $body) !== $evidence['content_sha256'] || strlen($body) !== $evidence['content_bytes']) {
            throw new CmsException('content_hash_mismatch');
        }
        [$begin, $end] = $this->markers($projection);
        if (str_contains($body, '<!--discussionbridge-source:')) {
            throw new CmsException('native_source_article_boundary_ambiguous');
        }
        $attributes = ['data-discussionbridge-source-profile' => self::PROFILE,
            'data-native-id' => $snapshot['entry_id'], 'data-site' => $snapshot['site_handle'],
            'data-collection' => $snapshot['collection_handle'], 'data-external-id' => $snapshot['external_id'],
            'data-source-revision' => $projection['source_revision'], 'data-move-id' => $projection['move_id'],
            'data-proof-nonce' => $projection['proof_nonce'], 'data-native-receipt-sha256' => $projection['receipt_sha256'],
            'data-title-sha256' => hash('sha256', $projection['source']['record']['title']),
            'data-authors-sha256' => ToSourceSnapshot::digest($projection['source']['record']['source_authors'] ?? [])];
        $html = $begin.'<article class="discussionbridge-article"';
        foreach ($attributes as $name => $value) {
            $html .= ' '.$name.'="'.htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
        }
        return $html.'>'.app(PageNavigation::class)->render($body).'</article>'.PresentationAssets::markup().$end;
    }

    public static function plainArticle(string $html): string
    {
        return '<article class="discussionbridge-article">'.app(PageNavigation::class)->render($html).'</article>'.PresentationAssets::markup();
    }

    public function renderPage($entry): string
    {
        return \Statamic\View\View::make($entry->template())->layout($entry->layout())->cascadeContent($entry)->render();
    }

    /** Independently render the pinned native template, including Antlers' own
     * final line-ending transformation. Never derive expected bytes from HTTP. */
    public function expectedFrame(array $projection): string
    {
        $actual = $this->snapshots->read((object) $projection['row']);
        if ($actual['snapshot'] !== $projection['snapshot']) {
            throw new CmsException('native_ownership_conflict');
        }
        $page = $this->renderPage($actual['entry']);
        $this->assertActiveArticle($page, $projection, 'native_source_article_preservation_unavailable');
        [$begin, $end] = $this->markers($projection);
        $start = strpos($page, $begin);
        $finish = strpos($page, $end);
        if ($start === false || $finish === false || $finish < $start
            || substr_count($page, $begin) !== 1 || substr_count($page, $end) !== 1) {
            throw new CmsException('native_source_article_boundary_ambiguous');
        }
        $frame = substr($page, $start, $finish + strlen($end) - $start);
        if (! in_array($frame, $this->nativeByteVariants($this->frame($projection)), true)) {
            // A template modifier cannot qualify a lossy article merely by
            // retaining its markers. Only the actual native final newline
            // operation is allowed between the full pinned frame and output.
            throw new CmsException('native_source_article_preservation_unavailable');
        }
        return $frame;
    }

    public function assertWholePlainArticle(string $page, string $source): void
    {
        $count = 0;
        foreach ($this->nativeByteVariants(self::plainArticle($source)) as $expected) {
            $count += substr_count($page, $expected);
        }
        if ($count !== 1) {
            throw new CmsException('native_source_article_preservation_unavailable');
        }
    }

    private function nativeByteVariants(string $frame): array
    {
        // Installed Antlers RuntimeParser:437–440 transforms LF only when the
        // complete render buffer contains no CRLF. The independent View above
        // decides whether that operation actually occurred. Observed HTTP is
        // never normalized or used to construct either expected variant.
        return array_values(array_unique([$frame, ! str_contains($frame, "\r\n")
            ? str_replace("\n", "\r\n", $frame) : $frame]));
    }

    public function markers(array $projection): array
    {
        $identity = hash('sha256', self::PROFILE."\0".$projection['move_id']."\0".$projection['proof_nonce']);
        return ['<!--discussionbridge-source:'.$identity.':begin-->', '<!--discussionbridge-source:'.$identity.':end-->'];
    }

    /** Exact complete framed bytes, not a copied hash/nonce marker. */
    public function compare($stream, array $projection): string
    {
        $expected = $this->expectedFrame($projection);
        [$begin, $end] = $this->markers($projection);
        rewind($stream);
        $page = stream_get_contents($stream);
        if (! is_string($page)) {
            throw new CmsException('public_verification_failed');
        }
        $this->assertActiveArticle($page, $projection, 'public_verification_failed');
        unset($page);
        rewind($stream);
        $buffer = '';
        $inside = false;
        $count = $offset = 0;
        $observed = hash_init('sha256');
        while (! feof($stream) || $buffer !== '') {
            if (! feof($stream)) {
                $buffer .= fread($stream, 8192);
            }
            if (! $inside) {
                $position = strpos($buffer, $begin);
                if ($position === false) {
                    $buffer = feof($stream) ? '' : substr($buffer, -(strlen($begin) - 1));
                    continue;
                }
                if (++$count !== 1) {
                    throw new CmsException('public_verification_failed');
                }
                $buffer = substr($buffer, $position);
                $inside = true;
            }
            $endAt = strpos($buffer, $end);
            $length = $endAt === false ? max(0, strlen($buffer) - strlen($end) + 1) : $endAt + strlen($end);
            if ($length === 0 && feof($stream) || $offset + $length > strlen($expected)
                || substr($expected, $offset, $length) !== substr($buffer, 0, $length)) {
                throw new CmsException('public_verification_failed');
            }
            $offset += $length;
            hash_update($observed, substr($buffer, 0, $length));
            $buffer = substr($buffer, $length);
            if ($endAt !== false) {
                if ($offset !== strlen($expected)) {
                    throw new CmsException('public_verification_failed');
                }
                $inside = false;
            }
        }
        if ($inside || $count !== 1 || $offset !== strlen($expected)) {
            throw new CmsException('public_verification_failed');
        }
        // Every observed byte above was compared, so this hash is of that exact
        // observed full frame, not a public claim of its own hash.
        return hash_final($observed);
    }

    /** Context validation only: the existing comparison still owns every exact source byte. */
    private function assertActiveArticle(string $page, array $projection, string $failureCode): void
    {
        if (! class_exists(\DOMDocument::class) || preg_match('//u', $page) !== 1) {
            throw new CmsException($failureCode);
        }
        $prior = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument('1.0', 'UTF-8');
            if (! $document->loadHTML('<?xml encoding="UTF-8">'.$page, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                throw new CmsException($failureCode);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prior);
        }
        $xpath = new \DOMXPath($document);
        $articles = $xpath->query('//*[@data-discussionbridge-source-profile]');
        if ($articles === false || $articles->length !== 1 || ! $articles->item(0) instanceof \DOMElement
            || $articles->item(0)->getAttribute('data-discussionbridge-source-profile') !== self::PROFILE) {
            throw new CmsException($failureCode);
        }
        // The active DOM boundary must be the exact framed article, not a visible
        // decoy alongside matching bytes hidden in a raw-text/script container.
        [$begin, $end] = array_map(fn ($marker) => substr($marker, 4, -3), $this->markers($projection));
        $starts = $ends = [];
        foreach ($xpath->query('//comment()') as $comment) {
            if ($comment->nodeValue === $begin) $starts[] = $comment;
            if ($comment->nodeValue === $end) $ends[] = $comment;
        }
        $article = $articles->item(0);
        if (count($starts) !== 1 || count($ends) !== 1 || $article->parentNode === null
            || ! $article->parentNode->isSameNode($starts[0]->parentNode) || ! $article->parentNode->isSameNode($ends[0]->parentNode)) {
            throw new CmsException($failureCode);
        }
        $found = $finished = false;
        for ($node = $starts[0]->nextSibling; $node !== null; $node = $node->nextSibling) {
            if ($node->isSameNode($ends[0])) { $finished = true; break; }
            if ($node->isSameNode($article)) $found = true;
        }
        if (! $found || ! $finished) throw new CmsException($failureCode);
        for ($node = $articles->item(0); $node instanceof \DOMElement; $node = $node->parentNode) {
            if (in_array(strtolower($node->tagName), ['template', 'script', 'style', 'noscript', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes', 'plaintext'], true)
                || $node->hasAttribute('hidden') || $node->hasAttribute('inert') || $this->inactiveStyle($node->getAttribute('style'))) {
                throw new CmsException($failureCode);
            }
        }
    }

    /** Explicit inline hiding only; no stylesheet evaluation or arbitrary descendant ban. */
    private function inactiveStyle(string $style): bool
    {
        $declarations = []; $part = ''; $quote = null; $depth = 0; $length = strlen($style);
        for ($i = 0; $i < $length; $i++) {
            $char = $style[$i];
            if ($quote !== null) {
                $part .= $char;
                if ($char === '\\' && $i + 1 < $length) $part .= $style[++$i];
                elseif ($char === $quote) $quote = null;
            } elseif ($char === '/' && ($style[$i + 1] ?? '') === '*') {
                $end = strpos($style, '*/', $i + 2); if ($end === false) break; $part .= ' '; $i = $end + 1;
            } elseif ($char === '"' || $char === "'") { $quote = $char; $part .= $char; }
            elseif ($char === '(') { $depth++; $part .= $char; }
            elseif ($char === ')') { $depth = max(0, $depth - 1); $part .= $char; }
            elseif ($char === ';' && $depth === 0) { $declarations[] = $part; $part = ''; }
            else $part .= $char;
        }
        $declarations[] = $part;
        $effective = [];
        foreach ($declarations as $declaration) {
            $pair = explode(':', $declaration, 2); if (count($pair) !== 2) continue;
            $name = strtolower(trim($pair[0])); $important = preg_match('/!\s*important\s*\z/i', $pair[1]) === 1;
            $value = strtolower(trim(preg_replace('/\s*!\s*important\s*\z/i', '', $pair[1]) ?? ''));
            $known = match ($name) {
                'display' => ['none', 'block', 'inline', 'inline-block', 'flow-root', 'list-item', 'flex', 'inline-flex', 'grid', 'inline-grid', 'contents', 'table', 'inline-table', 'table-row-group', 'table-header-group', 'table-footer-group', 'table-row', 'table-cell', 'table-column-group', 'table-column', 'table-caption'],
                'visibility' => ['visible', 'hidden', 'collapse'],
                'content-visibility' => ['visible', 'auto', 'hidden'],
                default => [],
            };
            // Unknown/computed values cannot clear a known hiding declaration.
            if (! in_array($value, $known, true)) continue;
            if (! isset($effective[$name]) || ! $effective[$name]['important'] || $important) {
                $effective[$name] = ['value' => $value, 'important' => $important];
            }
        }
        return ($effective['display']['value'] ?? null) === 'none'
            || in_array($effective['visibility']['value'] ?? null, ['hidden', 'collapse'], true)
            || ($effective['content-visibility']['value'] ?? null) === 'hidden';
    }
}
