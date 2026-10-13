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
    public static function fromEntry(Entry $entry, Configuration $configuration, ?string $previousRevision = null): array
    {
        if ($previousRevision !== null && ! preg_match('/\A[0-9a-f]{64}\z/', $previousRevision)) {
            throw new RuntimeException('Statamic accepted source revision is invalid.');
        }
        $html = PublishedContent::portableFromEntry($entry);
        $title = $entry->get('title');
        if (! is_string($title) || trim($title) === '' || strlen($title) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $title)) {
            throw new RuntimeException('Statamic entry title is invalid.');
        }
        $canonical = $configuration->canonicalPageUrl($entry->absoluteUrl());
        $authorship = $configuration->sourceAuthor();
        $created = self::date($entry->get('created_at') ?? $entry->get('date'));
        $updated = self::date($entry->get('updated_at'));
        if ($created !== null && $updated !== null && SourceTimestamp::precedes($updated, $created)) {
            throw new RuntimeException('Statamic native source chronology is invalid.');
        }
        $revision = hash('sha256', self::json([$title, $html, $authorship, $created, $updated]));
        $excerpted = strlen($html) > PublishedContent::MAX_HTML_BYTES;

        return [
            'full_html' => $html,
            'record' => [
                'title' => $title, 'canonical_url' => $canonical,
                'content_html' => $excerpted ? self::excerpt($html, $canonical) : $html,
                ...$authorship,
                'source_publication' => ['revision' => $revision, 'previous_revision' => $previousRevision, 'created_at' => $created, 'updated_at' => $updated, 'content_sha256' => hash('sha256', $html), 'content_bytes' => strlen($html), 'excerpted' => $excerpted],
            ],
        ];
    }

    public static function fitEnvelope(array $payload, string $completeHtml): array
    {
        $maximum = PublishedContent::MAX_HTML_BYTES;
        while (strlen(self::json(['bridge_record' => $payload])) > 65536) {
            $maximum = intdiv($maximum, 2);
            $payload['content_html'] = self::excerpt($completeHtml, $payload['canonical_url'], $maximum);
            $payload['source_publication']['excerpted'] = true;
        }

        return $payload;
    }

    private static function excerpt(string $html, string $canonical, int $maximum = PublishedContent::MAX_HTML_BYTES): string
    {
        $footer = '<p><a href="'.htmlspecialchars($canonical, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">Read More</a></p>';
        if ($maximum <= strlen($footer) + 7) {
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
        $budget = intdiv($maximum - strlen($footer) - 7, 6);
        $prefix = mb_strcut(trim((string) $text), 0, $budget, 'UTF-8');

        return '<p>'.htmlspecialchars($prefix, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p>'.$footer;
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
