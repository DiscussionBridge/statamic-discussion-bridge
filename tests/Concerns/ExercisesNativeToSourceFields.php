<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests\Concerns;

use CodeWorksLabs\DiscussionBridgeStatamic\Delivery\SourcePublication;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use Illuminate\Support\Facades\DB;
use Statamic\Facades\Entry;

/** Actual authoring repositories; no receiver acceptance or deployment claim. */
trait ExercisesNativeToSourceFields
{
    public static function nativePreparationContentCases(): array
    {
        return [['unformatted native text'], ['![](../media/original.png "Original image")']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nativePreparationContentCases')]
    public function test_native_to_plain_and_image_only_content_capture_originals_without_prose_eligibility_gate(string $source): void
    {
        config(['discussionbridge.collections' => ['discussionbridge']]);
        $entry = Entry::make()->collection('discussionbridge')->locale('default')->slug('native-preparation')
            ->published(true)->data(['title' => 'Original source', 'content' => $source, 'discussionbridge_publish' => true]);
        $this->assertTrue($entry->save());
        $row = DB::table('discussionbridge_deliveries')->sole();
        $publication = SourcePublication::fromEntry($entry, app(Configuration::class));
        $this->assertSame($source, $entry->get('content'));
        $this->assertSame($source, base64_decode($publication['native_source']['content_base64'], true));
        $this->assertSame($entry->absoluteUrl(), $publication['native_source']['article_url']);
        $this->assertSame(hash('sha256', $source), $publication['native_source']['content_sha256']);
        $this->assertSame(strlen($source), $publication['native_source']['content_bytes']);
        if (str_starts_with($source, '![]')) {
            $this->assertSame('', trim(strip_tags($publication['full_html'])));
            $this->assertStringContainsString('src="https://statamic.example/media/original.png"', $publication['full_html']);
        }
        $this->assertSame(hash('sha256', $publication['full_html']), $publication['record']['source_publication']['content_sha256']);
        $this->assertSame(strlen($publication['full_html']), $publication['record']['source_publication']['content_bytes']);
        $captured = app(\CodeWorksLabs\DiscussionBridgeStatamic\Cms\ToSourcePreparation::class)->capture($row);
        $this->assertSame($publication['full_html'], $captured['full_html']);
        $this->assertSame($publication['native_source'], $captured['native_source']);
    }

    public function test_native_to_captured_article_url_resolves_parsed_targets_before_complete_digest_without_changing_native_parser_options(): void
    {
        config(['discussionbridge.collections' => ['discussionbridge'],
            'statamic.markdown.configs.default.allow_unsafe_links' => false]);
        $source = '[relative](../guide/page?x=1#part) ![caption](images/native.png) [absolute](https://elsewhere.example/fixed) [blocked](javascript:alert%281%29)';
        $entry = Entry::make()->collection('discussionbridge')->locale('default')->slug('original-article')
            ->published(true)->data(['title' => 'Original article', 'content' => $source, 'discussionbridge_publish' => true]);
        $this->assertTrue($entry->save());
        $originalUrl = $entry->absoluteUrl();
        $originalPath = $entry instanceof \Statamic\Eloquent\Entries\Entry ? null : $entry->path();
        // A supported native extension simulates an object changing DURING
        // rendering. Capture must already bind the original bytes and URL.
        \Statamic\Facades\Markdown::addExtension(fn () => new class($entry) implements \League\CommonMark\Extension\ExtensionInterface {
            public function __construct(private readonly object $entry) {}
            public function register(\League\CommonMark\Environment\EnvironmentBuilderInterface $environment): void
            {
                $environment->addEventListener(\League\CommonMark\Event\DocumentParsedEvent::class,
                    fn () => $this->entry->slug('later-destination')->set('content', 'Later unsaved source.'));
            }
        });
        $publication = SourcePublication::fromEntry($entry, app(Configuration::class));
        $this->assertSame($source, base64_decode($publication['native_source']['content_base64'], true));
        $this->assertSame($originalUrl, $publication['native_source']['article_url']);
        $this->assertSame($originalUrl, $publication['record']['canonical_url']);
        $this->assertStringContainsString('href="https://statamic.example/guide/page?x=1#part"', $publication['full_html']);
        $this->assertStringContainsString('src="https://statamic.example/discussionbridge/images/native.png"', $publication['full_html']);
        $this->assertStringContainsString('href="https://elsewhere.example/fixed"', $publication['full_html']);
        $this->assertStringNotContainsString('href="javascript:', $publication['full_html']);
        $this->assertStringNotContainsString('later-destination', $publication['full_html']);
        $this->assertStringNotContainsString('Later unsaved source', $publication['full_html']);
        $this->assertSame(hash('sha256', $publication['full_html']), $publication['record']['source_publication']['content_sha256']);
        $this->assertSame(strlen($publication['full_html']), $publication['record']['source_publication']['content_bytes']);
        $persistedOriginal = $originalPath === null ? $entry->model()->fresh()->data['content'] : file_get_contents($originalPath);
        $this->assertStringContainsString('../guide/page', $persistedOriginal);
    }

    public function test_native_to_unsupported_nontext_field_is_bridge_preparation_limitation_not_source_ineligibility(): void
    {
        $source = [['type' => 'image', 'attrs' => ['src' => 'original.png']]];
        $entry = Entry::make()->collection('discussionbridge')->locale('default')->slug('native-nontext')
            ->published(true)->data(['title' => 'Native nontext source', 'content' => $source, 'discussionbridge_publish' => true]);
        try {
            SourcePublication::fromEntry($entry, app(Configuration::class));
            $this->fail('Unsupported native field was treated as a selected preparation recipe.');
        } catch (\CodeWorksLabs\DiscussionBridgeStatamic\Cms\NativePreparationLimitation $error) {
            $this->assertSame('native_source_html_unqualified', $error->failureCode);
            $this->assertSame('native_content_field_not_supported', $error->reason);
        }
        $this->assertTrue($entry->published());
        $this->assertTrue($entry->get('discussionbridge_publish'));
        $this->assertSame($source, $entry->get('content'));
    }

    public function test_native_to_raw_media_relative_attribute_recipe_is_not_inferred_from_markdown_node_support(): void
    {
        config(['discussionbridge.collections' => ['discussionbridge']]);
        $source = '<video poster="../poster.png"><source src="../clip.mp4"></video>';
        $entry = Entry::make()->collection('discussionbridge')->locale('default')->slug('native-raw-media')
            ->published(true)->data(['title' => 'Native raw media', 'content' => $source, 'discussionbridge_publish' => true]);
        $this->assertTrue($entry->save());
        $row = DB::table('discussionbridge_deliveries')->sole();
        try {
            app(\CodeWorksLabs\DiscussionBridgeStatamic\Cms\ToSourcePreparation::class)->capture($row);
            $this->fail('Parsed Link/Image support was treated as complete raw-media preparation.');
        } catch (\CodeWorksLabs\DiscussionBridgeStatamic\Cms\CmsException $error) {
            $this->assertSame('native_source_html_unqualified', $error->failureCode);
        }
        $this->assertSame($source, $entry->get('content'));
        $this->assertTrue($entry->published());
        $this->assertTrue($entry->get('discussionbridge_publish'));
        $this->assertNull($row->cms_source_revision);
    }

    public function test_native_to_source_fields_and_save_queue_retain_identity_across_editions(): void
    {
        config(['discussionbridge.collections' => ['discussionbridge'], 'statamic.system.track_last_update' => true]);
        $this->travelTo(\Carbon\Carbon::parse('2026-09-02T09:10:11Z'));
        $updated = (new \DateTimeImmutable('2026-09-02T09:10:11Z'))->getTimestamp();
        $entry = Entry::make()->collection('discussionbridge')->locale('default')->slug('native-to-source')
            ->published(true)->data(['title' => 'Native source title', 'content' => "## Native source\n\nComplete source é ending.",
                'discussionbridge_publish' => true, 'created_at' => '2026-09-01T08:00:00Z', 'updated_at' => $updated]);
        $this->assertTrue($entry->save());
        $first = SourcePublication::fromEntry(Entry::find($entry->id()), app(Configuration::class));
        $row = DB::table('discussionbridge_deliveries')->sole();
        $this->assertSame((string) $entry->id(), $row->entry_id);
        $this->assertSame($entry->absoluteUrl(), $row->canonical_url);
        $this->assertSame($first['record']['source_publication']['revision'], $row->desired_revision);
        $this->assertSame($entry instanceof \Statamic\Eloquent\Entries\Entry ? null : '2026-09-01T08:00:00Z', $first['record']['source_publication']['created_at']);
        $this->assertSame('2026-09-02T09:10:11+00:00', $first['record']['source_publication']['updated_at']);
        $this->assertSame(hash('sha256', $first['full_html']), $first['record']['source_publication']['content_sha256']);
        $this->assertSame(strlen($first['full_html']), $first['record']['source_publication']['content_bytes']);
        $this->assertStringContainsString('Complete source é ending.', $first['full_html']);
        $this->assertSame('https://statamic.example/authors/statamic-author', $first['record']['source_authors'][0]['profile_url']);
        $this->assertSame($first['record']['source_authors'][0]['id'], $first['record']['primary_source_author_id']);

        $this->travelTo(\Carbon\Carbon::parse('2026-09-02T09:11:11Z'));
        $entry->set('content', "## Native source\n\nChanged source é final ending.")
            ->set('updated_at', $updated + 60);
        $this->assertTrue($entry->save());
        $second = SourcePublication::fromEntry(Entry::find($entry->id()), app(Configuration::class));
        $after = DB::table('discussionbridge_deliveries')->sole();
        foreach (['id', 'entry_id', 'external_id', 'canonical_url', 'site_handle', 'collection_handle'] as $field) {
            $this->assertSame($row->{$field}, $after->{$field});
        }
        $this->assertSame($first['record']['source_publication']['created_at'], $second['record']['source_publication']['created_at']);
        $this->assertSame('2026-09-02T09:11:11+00:00', $second['record']['source_publication']['updated_at']);
        $this->assertNotSame($first['record']['source_publication']['revision'], $second['record']['source_publication']['revision']);
        $this->assertSame($second['record']['source_publication']['revision'], $after->desired_revision);
        $this->assertSame('pending', $after->status);
        $this->assertNull($after->cms_source_revision, 'Native saves cannot invent a receiver-accepted predecessor.');
    }

    public function test_native_to_unknown_source_times_stay_unknown_instead_of_save_or_build_time(): void
    {
        config(['discussionbridge.collections' => ['discussionbridge'], 'statamic.system.track_last_update' => false]);
        $entry = Entry::make()->collection('discussionbridge')->locale('default')->slug('native-to-unknown-times')
            ->published(true)->data(['title' => 'Unknown source instants', 'content' => 'A complete native source.',
                'discussionbridge_publish' => true, 'created_at' => '2026-09-01']);
        $this->assertTrue($entry->save());
        $publication = SourcePublication::fromEntry(Entry::find($entry->id()), app(Configuration::class));
        $this->assertNull($publication['record']['source_publication']['created_at']);
        $this->assertNull($publication['record']['source_publication']['updated_at']);
        $this->assertSame($publication['record']['source_publication']['revision'], DB::table('discussionbridge_deliveries')->sole()->desired_revision);
    }
}
