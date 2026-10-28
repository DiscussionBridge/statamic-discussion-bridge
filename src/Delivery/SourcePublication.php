<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Delivery;

use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\SourceTimestamp;
use DateTimeImmutable;
use DateTimeInterface;
use DOMDocument;
use DOMXPath;
use RuntimeException;
use Statamic\Contracts\Entries\Entry;

class SourcePublication
{
    public static function fromEntry(Entry $entry, Configuration $configuration, ?string $capturedArticleUrl = null): array
    {
        // Capture the SOURCE ARTICLE URL before native rendering, together with
        // original bytes. Later destination/canonical changes cannot rebase it.
        $canonical = $configuration->canonicalPageUrl($entry->absoluteUrl());
        $capturedArticleUrl ??= app(\CodeWorksLabs\DiscussionBridgeStatamic\Cms\ToSourceArticleProfile::class)->baseFor($entry);
        $captured = PublishedContent::captureEntry($entry, $capturedArticleUrl ?? $canonical);
        $html = $captured['html'];
        $title = $entry->get('title');
        if (! is_string($title) || trim($title) === '' || strlen($title) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $title)) {
            throw new RuntimeException('Statamic entry title is invalid.');
        }
        $authorship = $configuration->sourceAuthor();
        $createdValue = $entry->get('created_at') ?? $entry->get('date');
        if ($entry instanceof \Statamic\Eloquent\Entries\Entry) {
            // Reserved created_at data is discarded by the native driver. A
            // transient save object cannot establish original source creation,
            // and model row-insert time is not substituted for that fact.
            $createdValue = $entry->model()?->data['created_at'] ?? null;
        }
        $created = self::date($createdValue);
        $updatedValue = $entry->get('updated_at');
        if ($entry instanceof \Statamic\Eloquent\Entries\Entry) {
            // The native driver refreshes its persisted model after save but
            // does not refresh data.updated_at on that same Entry object.
            $updatedValue = config('statamic.system.track_last_update') === true ? $entry->model()?->updated_at : null;
        }
        $updated = self::date($updatedValue);
        if ($created !== null && $updated !== null && SourceTimestamp::precedes($updated, $created)) {
            throw new RuntimeException('Statamic native source chronology is invalid.');
        }
        $revision = hash('sha256', self::json([$title, $html, $authorship, $created, $updated]));

        return [
            'native_source' => $captured['original'],
            'full_html' => $html,
            'record' => [
                'title' => $title, 'canonical_url' => $canonical,
                'content_html' => $html,
                ...$authorship,
                'source_publication' => ['revision' => $revision, 'created_at' => $created, 'updated_at' => $updated, 'content_sha256' => hash('sha256', $html), 'content_bytes' => strlen($html)],
            ],
        ];
    }

    public static function excerptArticle(string $html, int $maximum): string
    {
        if ($maximum <= 7) {
            throw new RuntimeException('Statamic publication metadata does not fit the transport envelope.');
        }
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><div id="discussionbridge-excerpt">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($document);
        foreach ($xpath->query('//script|//style|//iframe|//object|//embed') as $node) {
            $node->parentNode?->removeChild($node);
        }
        $root = $xpath->query('//*[@id="discussionbridge-excerpt"]')->item(0);
        $text = preg_replace('/\s+/u', ' ', (string) $root?->textContent);
        $budget = intdiv($maximum - 7, 6);
        $prefix = mb_strcut(trim((string) $text), 0, $budget, 'UTF-8');

        return '<p>'.htmlspecialchars($prefix, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p>';
    }

    private static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('u') === '000000' ? $value->format('c') : $value->format('Y-m-d\TH:i:s.uP');
        }
        if (is_int($value) && $value >= 0) {
            return (new DateTimeImmutable('@'.$value))->format('c');
        }
        // A day without a time/zone is not an authoritative source instant.
        if (is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value)) {
            return null;
        }
        return SourceTimestamp::validate($value, 'Statamic native source date is invalid.');
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
