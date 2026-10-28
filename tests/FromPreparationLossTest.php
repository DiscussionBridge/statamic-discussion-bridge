<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Cms\CmsClient;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\CmsException;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\ContentPreservationLimitation;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\NativePolicy;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\NativeWriteGuard;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\NativeWriter;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\OwnedFragments;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\PrivateState;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\PublicVerifier;
use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\HtmlSanitizer;
use CodeWorksLabs\DiscussionBridgeStatamic\Publication\PublicationFreshness;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use Mockery;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Statamic\Facades\Entry;

/** Actual sanitizer/writer/fragment comparator; native storage/fence/revision are explicit in-memory seams. */
abstract class FromPreparationLossFixture extends TestCase
{
    protected string $fixtureRoot;

    protected function setUp(): void
    {
        $this->fixtureRoot = sys_get_temp_dir().'/db-statamic-preservation-'.bin2hex(random_bytes(10));
        if (file_exists($this->fixtureRoot) || ! mkdir($this->fixtureRoot, 0700)) {
            throw new RuntimeException('Cannot create an exclusive preservation fixture.');
        }
        foreach (['bootstrap/cache', 'config', 'resources/views', 'storage/framework/views', 'storage/framework/cache', 'storage/logs'] as $directory) {
            mkdir($this->fixtureRoot.'/'.$directory, 0700, true);
        }
        file_put_contents($this->fixtureRoot.'/composer.json', '{"autoload":{"psr-4":{"App\\\\":"app/"}}}');
        parent::setUp();
        view()->getFinder()->addExtension('antlers.html');
        file_put_contents($this->fixtureRoot.'/resources/views/native-publication.antlers.html',
            '{{ discussionbridge:article }}{{ discussionbridge:publication }}');
    }

    protected function getApplicationBasePath()
    {
        return $this->fixtureRoot;
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('view.paths', [$this->fixtureRoot.'/resources/views']);
        $app['config']->set('view.compiled', $this->fixtureRoot.'/storage/framework/views');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('logging.default', 'null');
        $app['config']->set('discussionbridge.forum_url', 'https://forum.example');
        $app['config']->set('discussionbridge.site_origin', 'https://statamic.example');
        $app['config']->set('discussionbridge.rich_content', false);
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            if (isset($this->fixtureRoot) && is_dir($this->fixtureRoot)) {
                $resolved = realpath($this->fixtureRoot);
                $expected = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.basename($this->fixtureRoot);
                if ($resolved !== $expected || ! preg_match('/\Adb-statamic-preservation-[a-f0-9]{20}\z/', basename($resolved))) {
                    throw new RuntimeException('Refusing cleanup outside the newly owned fixture.');
                }
                $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($entries as $entry) {
                    if ($entry->isLink()) {
                        throw new RuntimeException('Refusing linked fixture cleanup.');
                    }
                    $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
                }
                rmdir($resolved);
            }
        }
    }

}

class FromPreparationLossTest extends FromPreparationLossFixture
{
    public static function unrepresentedMeaning(): array
    {
        return [
            'animation-event' => ['<p onanimationend="choose()">Choose</p>', 'native_active_function_unrepresented'],
            'focus-event' => ['<p onfocus="choose()" tabindex="0">Choose</p>', 'native_active_function_unrepresented'],
            'wheel-event' => ['<p onwheel="choose()">Choose</p>', 'native_active_function_unrepresented'],
            'responsive-image' => ['<img src="https://source.example/base.png" srcset="https://source.example/required-content.png 2x" sizes="100vw" alt="Content">', 'native_media_unrepresented'],
            'css-generated-content' => ['<style>.answer::before{content:"Approved"}</style><p class="answer"> status</p>', 'native_active_function_unrepresented'],
            'inline-presentation' => ['<p style="white-space:pre">A     B</p>', 'native_active_function_unrepresented'],
        ];
    }

    #[DataProvider('unrepresentedMeaning')]
    public function test_actual_article_preparation_reports_specific_loss_before_returning_a_copy(string $html, string $reason): void
    {
        try {
            (new HtmlSanitizer())->sanitizeArticle($html, 'https://forum.example/t/source/53');
            $this->fail('Preparation silently returned a copy missing source meaning.');
        } catch (ContentPreservationLimitation $error) {
            $this->assertSame($reason, $error->reason);
            $this->assertNotEmpty($error->lostFunction ?? $error->lostData);
            $this->assertStringNotContainsString('content_preservation_unavailable', $error->getMessage());
        }
    }

    public static function sharedWriterLosses(): array
    {
        $cases = [];
        $meaning = self::unrepresentedMeaning();
        foreach (['statamic_flat', 'statamic_db', 'statamic_ssg'] as $profile) {
            foreach (['animation-event', 'responsive-image', 'css-generated-content'] as $name) {
                $cases[$profile.'-'.$name] = [$profile, $meaning[$name][0], $meaning[$name][1]];
            }
        }
        return $cases;
    }

    #[DataProvider('sharedWriterLosses')]
    public function test_shared_native_writer_cannot_save_or_prove_a_lossy_full_publication(string $profile, string $html, string $reason): void
    {
        $seam = $this->writerSeam($profile, $html);
        $beforeState = $seam->state;
        $beforeData = $seam->data;
        $rejected = false;
        try {
            $result = $seam->writer->apply($seam->state, $seam->connection, $seam->policy, $seam->mapping,
                $seam->lease, $seam->source, $seam->path);
            // The old defect reaches a real full result and the real fragment
            // comparator accepts its already-lossy expectation. Never treat this as native qualification.
            $this->assertSame('full', $result->representation->kind);
            $this->compareActualFragments($seam);
        } catch (ContentPreservationLimitation $error) {
            $rejected = true;
            $this->assertSame($reason, $error->reason);
        }
        $this->assertTrue($rejected, 'A lossy full result reached owned-fragment proof instead of a local limitation.');
        $this->assertSame(0, $seam->nativeSaves);
        $this->assertSame(0, $seam->nativeStages);
        $this->assertSame([], $seam->privateSaves);
        $this->assertSame($beforeState, $seam->state);
        $this->assertSame($beforeData, $seam->data);
        $this->assertSame($html, file_get_contents($seam->path));
        $this->assertSame(hash('sha256', $html), $seam->source->content_sha256);
    }

    public function test_supported_article_keeps_plain_images_tables_lists_disclosure_and_displayed_math_code(): void
    {
        $html = '<h2>Source heading</h2><p>Unformatted meaning.</p><figure><img src="../diagram.png" alt="Plot" width="640" height="480">'
            .'<figcaption>Plot meaning</figcaption></figure><table><caption>Results</caption><tr><th scope="col">Column</th><td rowspan="2" colspan="2">Value</td></tr></table>'
            .'<ol start="5" reversed type="I"><li value="7">Numbered item</li></ol><details open name="group"><summary>Inspect</summary><p>Explanation</p></details>'
            .'<pre><code class="language-math">x^2 + y^2</code></pre><pre><code class="language-mermaid">graph TD; A--&gt;B</code></pre>'
            .'<pre><code>&lt;p onanimationend="shown_not_run()"&gt;Example&lt;/p&gt;</code></pre>';
        $seam = $this->writerSeam('statamic_flat', $html);
        $result = $seam->writer->apply($seam->state, $seam->connection, $seam->policy, $seam->mapping,
            $seam->lease, $seam->source, $seam->path);
        $this->assertSame('full', $result->representation->kind);
        $this->assertSame(1, $seam->nativeSaves);
        foreach (['Unformatted meaning.', 'src="https://forum.example/t/diagram.png"', 'alt="Plot"', '<caption>Results</caption>',
            'rowspan="2"', 'colspan="2"', 'start="5"', 'value="7"', 'name="group"', 'language-math', 'x^2 + y^2', 'language-mermaid', 'shown_not_run()'] as $retained) {
            $this->assertStringContainsString($retained, $seam->data['content']);
        }
        $this->compareActualFragments($seam);
        $this->assertSame($html, file_get_contents($seam->path));
        $this->assertArrayNotHasKey('pending', $seam->state['mappings'][$seam->mapping->mapping_id]);
    }

    public function test_wire_integrity_is_checked_before_even_a_specific_preparation_limitation(): void
    {
        $seam = $this->writerSeam('statamic_flat', '<p onanimationend="choose()">Original source</p>');
        $seam->source->content_sha256 = str_repeat('0', 64);
        try {
            $seam->writer->apply($seam->state, $seam->connection, $seam->policy, $seam->mapping,
                $seam->lease, $seam->source, $seam->path);
            $this->fail('Bad immutable wire bytes must fail before native preparation.');
        } catch (CmsException $error) {
            $this->assertSame('content_hash_mismatch', $error->failureCode);
        }
        $this->assertSame(0, $seam->nativeSaves);
        $this->assertSame([], $seam->privateSaves);
    }

    private function compareActualFragments(object $seam): void
    {
        $fragments = new OwnedFragments();
        $expected = $fragments->expected($seam->entry);
        $stream = fopen('php://memory', 'w+b');
        try {
            fwrite($stream, '<html><body>'.$expected['article'].$expected['discussion'].'</body></html>');
            (new PublicVerifier(new Configuration(), $fragments))->compareFragments($stream, $seam->mapping->mapping_id, $expected);
            $this->assertNotEmpty($expected['article']);
        } finally {
            fclose($stream);
        }
    }

    private function writerSeam(string $profile, string $html): object
    {
        config(['discussionbridge.cms_profile' => $profile]);
        $seam = (object) ['nativeSaves' => 0, 'nativeStages' => 0, 'privateSaves' => []];
        $mappingId = '75bc93ce-5f76-4cb8-a2b6-3c4bb33fb250';
        $resourceId = '75bc93ce-5f76-4cb8-a2b6-3c4bb33fb251';
        $nativeId = '75bc93ce-5f76-4cb8-a2b6-3c4bb33fb252';
        $seam->mapping = (object) ['mapping_id' => $mappingId, 'resource_id' => $resourceId,
            'connection_id' => 'dbc_0123456789abcdef01234567', 'source_id' => 'discourse:topic:53',
            'external_id' => 'native:source:53', 'canonical_url' => 'https://statamic.example/source',
            'presentation_mode' => 'full', 'desired_source_revision' => 'source:2', 'destination_native_id' => $nativeId];
        $seam->source = (object) ['source_id' => 'discourse:topic:53', 'source_revision' => 'source:2', 'topic_id' => '53',
            'topic_url' => 'https://forum.example/t/source/53', 'title' => 'Current source', 'content_bytes' => strlen($html),
            'content_sha256' => hash('sha256', $html), 'source_authors' => [], 'source_created_at' => null, 'source_updated_at' => null];
        $seam->connection = (object) ['connection_id' => $seam->mapping->connection_id, 'forum_name' => 'Source forum'];
        $seam->policy = (object) ['policy_revision' => 'policy:1', 'presentation_mode' => 'full', 'enabled' => true];
        $work = (object) ['mapping_id' => $mappingId, 'source_id' => $seam->source->source_id,
            'source_revision' => 'source:2', 'policy_revision' => 'policy:1', 'action' => 'publish_revision', 'work_id' => '75bc93ce-5f76-4cb8-a2b6-3c4bb33fb253'];
        $seam->lease = (object) ['work' => $work, 'lease_generation' => 2, 'expires_at' => '2099-01-01T00:00:00.000Z'];
        $configuration = new Configuration();
        $client = new CmsClient($configuration);
        $seam->state = ['mappings' => [$mappingId => ['mapping' => $client->codec()->encodeJson($seam->mapping),
            'native_id' => $nativeId, 'native_revision' => 'native:before', 'generation' => 1,
            'receipt' => 'UNCHANGED-ACCEPTED-RECEIPT', 'last_verified' => ['source_revision' => 'source:1']]],
            'mutations' => ['prior-request' => ['body' => 'UNCHANGED-PENDING-HISTORY']]];
        $seam->data = ['title' => 'Accepted prior title', 'content' => '<p>Accepted prior complete article.</p>',
            'discussionbridge_cms_owned' => true, 'discussionbridge_cms_mapping_id' => $mappingId,
            'discussionbridge_resource_id' => $resourceId];
        $entry = Mockery::mock(\Statamic\Entries\Entry::class);
        $entry->shouldReceive('id')->andReturn($nativeId);
        $entry->shouldReceive('get')->andReturnUsing(fn ($name) => $seam->data[$name] ?? null);
        $entry->shouldReceive('data')->andReturnUsing(function ($data = null) use ($seam, $entry) {
            if ($data === null) return collect($seam->data);
            $seam->data = $data;
            return $entry;
        });
        $entry->shouldReceive('absoluteUrl')->andReturn($seam->mapping->canonical_url);
        $entry->shouldReceive('uri')->andReturn('/source');
        $entry->shouldReceive('locale')->andReturn('default');
        $entry->shouldReceive('template')->andReturn('native-publication');
        $entry->shouldReceive('published')->andReturnSelf();
        $entry->shouldReceive('save')->andReturnUsing(function () use ($seam) { $seam->nativeSaves++; return true; });
        $repository = Mockery::mock(\Statamic\Contracts\Entries\EntryRepository::class);
        $repository->shouldReceive('findByUri')->with('/source', 'default')->andReturnNull();
        Entry::swap($repository);
        $store = Mockery::mock(PrivateState::class);
        $store->shouldReceive('save')->andReturnUsing(function ($state) use ($seam) { $seam->privateSaves[] = $state; });
        $policy = Mockery::mock(NativePolicy::class);
        $policy->shouldReceive('assignments')->andReturn([]);
        $guard = Mockery::mock(NativeWriteGuard::class);
        $guard->shouldReceive('assertRepositoryFence')->andReturnNull();
        $guard->shouldReceive('locked')->andReturnUsing(fn ($id, $callback) => $callback());
        $guard->shouldReceive('capture')->andReturnNull();
        $guard->shouldReceive('stage')->andReturnUsing(function () use ($seam) { $seam->nativeStages++; });
        $freshness = Mockery::mock(PublicationFreshness::class);
        $freshness->shouldReceive('refresh')->andReturnNull();
        $writer = Mockery::mock(NativeWriter::class, [$configuration, $client, $store, $policy, $guard, $freshness])->makePartial();
        $writer->shouldReceive('freshEntry')->with($nativeId)->andReturn($entry);
        $writer->shouldReceive('revision')->andReturn('native:before');
        $writer->shouldReceive('stateDigest')->andReturnUsing(fn () => hash('sha256', json_encode($seam->data)));
        $seam->entry = $entry;
        $seam->writer = $writer;
        $seam->path = $this->fixtureRoot.'/wire-source.html';
        file_put_contents($seam->path, $html);
        return $seam;
    }
}
