<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests\Concerns;

use CodeWorksLabs\DiscussionBridgeStatamic\Cms\CmsException;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\NativePolicy;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Facades\User;

trait ExercisesCmsNativeMetadata
{
    protected function metadataEntry(array $fields = [])
    {
        $root = dirname(__DIR__, 2).'/.tmp/native-metadata-'.bin2hex(random_bytes(8));
        foreach (['users', 'taxonomies', 'terms', 'blueprints'] as $directory) {
            mkdir($root.'/'.$directory, 0777, true);
        }
        Stache::store('users')->directory($root.'/users');
        Stache::store('taxonomies')->directory($root.'/taxonomies');
        Stache::store('terms')->directory($root.'/terms');
        Blueprint::setDirectories($root.'/blueprints');
        foreach (['native_topics', 'native_regions', 'native_outside'] as $handle) {
            Taxonomy::make($handle)->title('Native '.$handle)->save();
            Term::make()->taxonomy($handle)->inDefaultLocale()->slug('shared-slug')
                ->data(['title' => 'Same display label'])->save();
        }
        foreach (['native-user:alpha', 'native-user:beta'] as $index => $id) {
            User::make()->id($id)->email('native'.$index.'@example.test')
                ->data(['name' => 'Same author label'])->save();
        }
        $blueprint = Blueprint::makeFromFields(array_merge([
            'content' => ['type' => 'markdown'],
            'native_author' => ['type' => 'users', 'max_items' => 1, 'default' => ['native-user:beta']],
            'native_topics' => ['type' => 'terms', 'taxonomies' => ['native_topics'], 'max_items' => 1,
                'default' => ['native_topics::shared-slug']],
            'native_note' => ['type' => 'text', 'default' => 'Native blueprint default'],
        ], $fields))->setHandle('native_metadata')->setNamespace('collections.discussionbridge');
        $blueprint->save();
        $collection = Collection::find('discussionbridge')->taxonomies(['native_topics', 'native_regions']);
        $collection->save();
        return Entry::make()->collection($collection)->blueprint('native_metadata')
            ->locale('default')->slug('native-metadata')->published(true)->set('blueprint', 'native_metadata');
    }

    public function test_selected_native_ids_use_actual_field_cardinality_and_single_taxonomy_storage(): void
    {
        $entry = $this->metadataEntry();
        $policy = (object) ['author_ids' => ['native-user:alpha'], 'taxonomy_ids' => ['native_topics::shared-slug']];
        $assigned = app(NativePolicy::class)->assignments($entry, $policy);
        $this->assertSame('native-user:alpha', $assigned['native_author']);
        $this->assertSame('shared-slug', $assigned['native_topics']);
        $entry->merge($assigned)->save();
        $saved = Entry::find($entry->id());
        if ($saved instanceof \Statamic\Eloquent\Entries\Entry) {
            $saved = \Statamic\Eloquent\Entries\Entry::fromModel($saved->model()->fresh());
        } else {
            $saved = Stache::store('entries')->store('discussionbridge')
                ->makeItemFromFile($saved->path(), file_get_contents($saved->path()));
        }
        $this->assertSame('native-user:alpha', $saved->get('native_author'));
        $this->assertSame('shared-slug', $saved->get('native_topics'));
        $this->assertSame('native-user:alpha', User::find($saved->get('native_author'))->id());
        $this->assertSame('native_topics::shared-slug', Term::find('native_topics::'.$saved->get('native_topics'))->id());
    }

    public function test_multiple_native_ids_keep_opaque_users_and_qualified_taxonomy_ids(): void
    {
        $entry = $this->metadataEntry([
            'native_author' => ['type' => 'users'],
            'native_topics' => ['type' => 'terms', 'taxonomies' => ['native_topics', 'native_regions']],
            'native_regions' => ['type' => 'hidden'],
        ]);
        $policy = (object) ['author_ids' => ['native-user:beta', 'native-user:alpha'],
            'taxonomy_ids' => ['native_regions::shared-slug', 'native_topics::shared-slug']];
        $assigned = app(NativePolicy::class)->assignments($entry, $policy);
        $this->assertSame($policy->author_ids, $assigned['native_author']);
        $this->assertSame($policy->taxonomy_ids, $assigned['native_topics']);
    }

    public function test_empty_selections_preserve_processed_native_defaults_and_current_assignments(): void
    {
        $entry = $this->metadataEntry();
        $mapper = app(NativePolicy::class);
        $defaults = $mapper->initialDefaults($entry);
        $this->assertSame('native-user:beta', $defaults['native_author']);
        $this->assertSame('shared-slug', $defaults['native_topics']);
        $this->assertSame('Native blueprint default', $defaults['native_note']);
        $policy = (object) ['author_ids' => [], 'taxonomy_ids' => []];
        $this->assertSame([], $mapper->assignments($entry, $policy));
        $entry->merge($defaults)->save();
        $entry->set('native_author', 'native-user:alpha')->set('native_note', 'Existing native note')->save();
        $entry->merge($mapper->assignments($entry, $policy))->save();
        $this->assertSame('native-user:alpha', Entry::find($entry->id())->get('native_author'));
        $this->assertSame('Existing native note', Entry::find($entry->id())->get('native_note'));
    }

    public static function unsupportedMetadataCases(): array
    {
        return [
            [['author_ids' => ['native-user:missing'], 'taxonomy_ids' => []], [], 'catalog_entry_unavailable'],
            [['author_ids' => ['native-user:alpha', 'native-user:beta'], 'taxonomy_ids' => []], [], 'catalog_entry_unavailable'],
            [['author_ids' => [], 'taxonomy_ids' => ['native_topics::missing']], [], 'catalog_entry_unavailable'],
            [['author_ids' => ['native-user:alpha'], 'taxonomy_ids' => []], ['other_author' => ['type' => 'users']], 'metadata_unavailable'],
            [['author_ids' => ['native-user:alpha'], 'taxonomy_ids' => []], ['native_author' => ['type' => 'users', 'query_scopes' => ['unknown_native_scope']]], 'metadata_unavailable'],
            [['author_ids' => [], 'taxonomy_ids' => ['native_outside::shared-slug']], [], 'metadata_unavailable'],
            [['author_ids' => [], 'taxonomy_ids' => ['native_topics::shared-slug']], ['duplicate_terms' => ['type' => 'terms', 'taxonomies' => ['native_topics']]], 'metadata_unavailable'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsupportedMetadataCases')]
    public function test_unavailable_or_unsupported_selected_metadata_requires_attention_without_native_mutation(array $selection, array $fields, string $code): void
    {
        $entry = $this->metadataEntry($fields)->set('native_author', 'native-user:beta');
        try {
            app(NativePolicy::class)->assignments($entry, (object) $selection);
            $this->fail('Unsupported native metadata must not silently substitute an ID or field.');
        } catch (CmsException $error) {
            $this->assertSame($code, $error->failureCode);
        }
        $this->assertSame('native-user:beta', $entry->get('native_author'));
        $this->assertNull($entry->id());
    }

    public function test_native_current_user_default_without_real_current_user_preserves_the_actual_native_outcome(): void
    {
        $entry = $this->metadataEntry(['native_author' => ['type' => 'users', 'default' => 'current']]);
        $this->assertNull(User::current());
        $field = $entry->blueprint()->fields()->all()->get('native_author');
        try {
            // Native Statamic6 versions either refuse this preparation or
            // produce an empty value. Bridge must not invent a user or replace
            // the native result with its own mandatory attention policy.
            $native = $field->fieldtype()->process($field->fieldtype()->preProcess($field->defaultValue()));
        } catch (\Throwable) {
            $this->expectException(CmsException::class);
            $this->expectExceptionMessage('metadata_unavailable');
            app(NativePolicy::class)->initialDefaults($entry);

            return;
        }
        $defaults = app(NativePolicy::class)->initialDefaults($entry);
        $this->assertSame($native, $defaults['native_author']);
        $this->assertEmpty($defaults['native_author']);
        $this->assertNull(User::current());
    }

    public function test_actual_native_default_preparation_failure_remains_metadata_unavailable(): void
    {
        $entry = $this->metadataEntry(['native_author' => ['type' => '__missing_native_fieldtype__']]);
        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('metadata_unavailable');
        app(NativePolicy::class)->initialDefaults($entry);
    }
}
