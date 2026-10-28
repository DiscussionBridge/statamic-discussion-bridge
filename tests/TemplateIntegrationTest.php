<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Support\TemplateIntegration;
use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\PublicationPresenter;
use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\StandalonePresenter;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\PresentationAssets;
use Illuminate\Support\LazyCollection;
use Mockery;
use RuntimeException;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

class TemplateIntegrationTest extends TestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $path) {
            unlink($path);
        }
        parent::tearDown();
    }

    public function test_native_selected_collection_and_entry_override_are_resolved_without_switching_templates(): void
    {
        $this->template('selected', '{{ partial:vendor/discussionbridge/entry }}');
        $this->template('vendor/discussionbridge/entry', file_get_contents(array_keys(PresentationAssets::templatePaths())[0]));
        $collection = Collection::make()->handle('pages')->template('selected');
        Collection::shouldReceive('findByHandle')->with('pages')->andReturn($collection);
        Collection::shouldReceive('findByHandle')->with('discussionbridge')->andReturnNull();
        $entry = Entry::make()->id('selected-entry')->collection('pages')->data(['discussionbridge_publish' => true, 'discussionbridge_mode' => 'full']);
        $query = Mockery::mock();
        $query->shouldReceive('whereIn')->andReturnSelf();
        $query->shouldReceive('orderBy')->with('id')->andReturnSelf();
        $query->shouldReceive('lazy')->with(32)->andReturn(LazyCollection::make([$entry]));
        Entry::shouldReceive('query')->andReturn($query);
        $this->assertSame('selected', $entry->template());
        $this->assertSame(['checked' => 1, 'incomplete' => []], app(TemplateIntegration::class)->inspect([]));
        $this->template('override', '<article>{{ content }}</article>');
        $entry->set('template', 'override');
        $report = app(TemplateIntegration::class)->inspect([]);
        $this->assertSame(1, $report['checked']);
        $this->assertStringContainsString('override', $report['incomplete'][0]);
        $this->assertSame('selected', $collection->template());
        $this->assertSame('override', $entry->template());
    }

    public function test_unselected_to_only_install_has_no_from_template_prerequisite(): void
    {
        $query = Mockery::mock();
        $query->shouldReceive('whereIn')->andReturnSelf();
        $query->shouldReceive('orderBy')->andReturnSelf();
        $query->shouldReceive('lazy')->with(32)->andReturn(LazyCollection::make([]));
        Entry::shouldReceive('query')->never();
        Collection::shouldReceive('findByHandle')->andReturnNull();
        $this->assertSame(['checked' => 0, 'incomplete' => []], app(TemplateIntegration::class)->inspect([]));
    }

    public function test_comments_noparse_unreachable_and_wrong_identity_calls_are_not_invocation_proof(): void
    {
        foreach ([
            '{{# {{ discussionbridge:article }} {{ discussionbridge:publication }} #}}',
            '{{ noparse }}{{ discussionbridge:article }}{{ discussionbridge:publication }}{{ /noparse }}',
            '{{ if false }}{{ discussionbridge:article }}{{ discussionbridge:publication }}{{ /if }}',
            '{{ discussionbridge:article entry="other" }}{{ discussionbridge:publication entry="other" }}',
            '{{ discussionbridge:article }}{{ discussionbridge:simple topic="99" }}',
            '{{ partial:vendor/discussionbridge/entry when="false" }}',
            '{{ discussionbridge:article entry="prefix{id}" }}{{ discussionbridge:publication }}',
            '{{ discussionbridge:article entry="{id | upper}" }}{{ discussionbridge:publication }}',
        ] as $index => $source) {
            $this->template('invalid-'.$index, $source);
            try {
                app(TemplateIntegration::class)->verify('invalid-'.$index, 'from_discourse', 'simple');
                $this->fail('Expected incomplete selected wiring.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('integration is incomplete', $error->getMessage());
            }
        }
    }

    public function test_supported_direct_calls_require_the_selected_mode_and_same_native_identity(): void
    {
        foreach (['simple', 'full', 'interactive'] as $mode) {
            $this->template('direct-'.$mode, '{{ discussionbridge:article entry="{id}" }}{{ discussionbridge:'.$mode.' topic="{discussionbridge_topic_id}" }}');
            app(TemplateIntegration::class)->verify('direct-'.$mode, 'from_discourse', $mode);
            $this->addToAssertionCount(1);
        }
        try {
            app(TemplateIntegration::class)->verify('direct-simple', 'from_discourse', 'full');
            $this->fail('A Simple invocation is not Full integration.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('integration is incomplete', $error->getMessage());
        }
    }

    public function test_native_partial_override_is_verified_instead_of_assuming_the_packaged_filename_is_used(): void
    {
        $this->template('partial-selected', '{{ partial:vendor/discussionbridge/entry }}');
        $this->template('vendor/discussionbridge/entry', file_get_contents(array_keys(PresentationAssets::templatePaths())[0]));
        $this->template('vendor/discussionbridge/_entry', '<p>Operator override without discussion invocation</p>');
        $this->expectException(RuntimeException::class);
        app(TemplateIntegration::class)->verify('partial-selected', 'from_discourse', 'interactive');
    }

    private function template(string $name, string $source): void
    {
        $path = resource_path('views/'.$name.'.antlers.html');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        $this->assertFalse(file_exists($path), 'The fixture must not replace existing template bytes.');
        file_put_contents($path, $source);
        $this->files[] = $path;
        view()->getFinder()->flush();
    }
}
