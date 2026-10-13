<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Delivery\SourcePublication;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use Mockery;
use Statamic\Contracts\Entries\Entry as EntryContract;
use RuntimeException;
use DateTimeImmutable;

class SourcePublicationTest extends TestCase
{
    public function test_complete_native_body_is_preserved_while_excerpt_and_full_digest_are_sent(): void
    {
        $source = '<h2>Complete source</h2><p>'.str_repeat('é & source ', 10000).'ENDING</p>';
        $entry = $this->entry($source);
        $publication = SourcePublication::fromEntry($entry, app(Configuration::class));
        $record = $publication['record'];
        $this->assertStringContainsString('ENDING', $publication['full_html']);
        $this->assertTrue($record['source_publication']['excerpted']);
        $this->assertLessThanOrEqual(49152, strlen($record['content_html']));
        $this->assertStringContainsString('<a href="https://statamic.example/source">Read More</a>', $record['content_html']);
        $this->assertSame(hash('sha256', $publication['full_html']), $record['source_publication']['content_sha256']);
        $this->assertSame(strlen($publication['full_html']), $record['source_publication']['content_bytes']);
        $this->assertNull($record['source_publication']['created_at']);
        $this->assertNull($record['source_publication']['updated_at']);
        $retry = SourcePublication::fromEntry($entry, app(Configuration::class), $record['source_publication']['revision']);
        $this->assertSame($record['source_publication']['revision'], $retry['record']['source_publication']['revision']);
        $this->assertSame($record['source_publication']['revision'], $retry['record']['source_publication']['previous_revision']);
    }

    public function test_native_dates_and_title_changes_produce_truthful_distinct_editions(): void
    {
        $first = SourcePublication::fromEntry($this->entry('<p>Body</p>', 'Title', '2026-08-01T08:00:00Z', 1788336000), app(Configuration::class));
        $second = SourcePublication::fromEntry($this->entry('<p>Body</p>', 'New title', '2026-08-01T08:00:00Z', 1788336000), app(Configuration::class));
        $this->assertSame('2026-08-01T08:00:00Z', $first['record']['source_publication']['created_at']);
        $this->assertNotNull($first['record']['source_publication']['updated_at']);
        $this->assertNotSame($first['record']['source_publication']['revision'], $second['record']['source_publication']['revision']);
    }

    public function test_native_dates_reject_normalized_instants_and_preserve_object_precision(): void
    {
        foreach (['2026-02-29T00:00:00Z', '2026-09-01T24:00:00Z', '2026-09-01T00:00:00+01:60'] as $value) {
            try {
                SourcePublication::fromEntry($this->entry('<p>Body</p>', 'Title', $value), app(Configuration::class));
                $this->fail('Expected invalid native instant rejection.');
            } catch (RuntimeException $error) {
                $this->assertSame('Statamic native source date is invalid.', $error->getMessage());
            }
        }
        $date = new DateTimeImmutable('2026-08-01T08:00:00.123456+02:00');
        $publication = SourcePublication::fromEntry($this->entry('<p>Body</p>', 'Title', $date, '2026-08-01T06:00:00.123456001Z'), app(Configuration::class));
        $this->assertSame('2026-08-01T08:00:00.123456+02:00', $publication['record']['source_publication']['created_at']);
    }

    public function test_native_chronology_refuses_an_earlier_nanosecond_in_the_same_microsecond(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Statamic native source chronology is invalid.');
        SourcePublication::fromEntry($this->entry('<p>Body</p>', 'Title', '2026-08-01T08:00:00.123456789Z', '2026-08-01T08:00:00.123456788Z'), app(Configuration::class));
    }

    private function entry(string $source, string $title = 'Title', mixed $created = null, mixed $updated = null): EntryContract
    {
        $entry = Mockery::mock(EntryContract::class);
        $entry->shouldReceive('get')->with('content')->andReturn($source);
        $entry->shouldReceive('get')->with('title')->andReturn($title);
        $entry->shouldReceive('get')->with('created_at')->andReturn($created);
        $entry->shouldReceive('get')->with('date')->andReturn(null);
        $entry->shouldReceive('get')->with('updated_at')->andReturn($updated);
        $entry->shouldReceive('absoluteUrl')->andReturn('https://statamic.example/source');

        return $entry;
    }
}
