<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests\Concerns;

use CodeWorksLabs\DiscussionBridgeStatamic\Cms\CmsException;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\NativeRepositories;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\NativeWriteGuard;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;
use Illuminate\Support\Facades\Event;
use Statamic\Events\EntrySaving;

trait ExercisesCmsNativeFence
{
    private function fencedEntry()
    {
        config(['discussionbridge.cms_state_directory' => dirname(__DIR__, 2).'/.tmp/cms-fence-'.bin2hex(random_bytes(8))]);
        NativeRepositories::install();
        $id = Stache::generateId();
        $entry = Entry::make()->id($id)->collection('discussionbridge')->locale('default')->slug('fence-'.bin2hex(random_bytes(8)))
            ->published(true)->data(['title' => 'Native fenced fixture', 'content' => 'First native body',
                'discussionbridge_cms_owned' => true, 'discussionbridge_cms_mapping_id' => '75bc93ce-5f76-4cb8-a2b6-3c4bb33fb24c']);
        app(NativeWriteGuard::class)->locked($id, function () use ($entry) {
            app(NativeWriteGuard::class)->capture($entry);
            return $entry->save();
        });
        return $entry;
    }

    public function test_cms_repository_rejects_stale_editor_and_worker_for_normal_quiet_and_direct_saves(): void
    {
        foreach (['normal', 'quiet', 'direct'] as $path) {
            $entry = $this->fencedEntry();
            $stale = $this->independentNativeEntry($entry);
            $entry->set('content', 'New worker content')->save();
            $stale->set('content', 'Stale editor body');
            try {
                $this->saveThrough($stale, $path);
                $this->fail('Stale native write erased newer content.');
            } catch (CmsException $error) {
                $this->assertSame('native_ownership_conflict', $error->failureCode);
            }
            $this->assertSame('New worker content', Entry::find($entry->id())->get('content'));
            $staleWorker = $this->independentNativeEntry($entry);
            $entry->set('content', 'New native editor body')->saveQuietly();
            $staleWorker->set('content', 'Stale worker body');
            try {
                app(NativeWriteGuard::class)->locked($entry->id(), function () use ($staleWorker, $path) {
                    app(NativeWriteGuard::class)->capture($staleWorker);
                    $this->saveThrough($staleWorker, $path);
                });
                $this->fail('Stale worker erased the native edit.');
            } catch (CmsException $error) {
                $this->assertSame('native_ownership_conflict', $error->failureCode);
            }
            $this->assertSame('New native editor body', Entry::find($entry->id())->get('content'));
        }
    }

    public function test_cms_normal_quiet_and_direct_contenders_cannot_write_inside_real_native_commit(): void
    {
        foreach (['normal', 'quiet', 'direct'] as $path) {
            $entry = $this->fencedEntry();
            $contender = $this->independentNativeEntry($entry);
            $contender->set('content', 'Contending editor body');
            $guard = app(NativeWriteGuard::class);
            $probes = 0;
            $probe = function () use ($path, $contender, $guard, &$probes) {
                $probes++;
                foreach ([$guard, new NativeWriteGuard] as $context) {
                    $this->app->instance(NativeWriteGuard::class, $context);
                    try {
                        $this->saveThrough($contender, $path);
                        $this->fail('Contender entered a native commit with a held lock.');
                    } catch (CmsException $error) {
                        $this->assertSame('work_busy', $error->failureCode);
                    } finally {
                        $this->app->instance(NativeWriteGuard::class, $guard);
                    }
                }
            };
            $entry->set('content', 'Actual worker commit');
            $this->insideNativeCommit($probe, fn () => $guard->locked($entry->id(), function () use ($entry, $guard) {
                $guard->capture($entry);
                return $entry->saveQuietly();
            }));
            $this->assertSame(1, $probes);
            $this->assertSame('Actual worker commit', Entry::find($entry->id())->get('content'));
            // Lock released after the complete native call; new editor object can save.
            $fresh = $this->independentNativeEntry($entry);
            $fresh->set('content', 'Subsequent native editor')->saveQuietly();
            $this->assertSame('Subsequent native editor', Entry::find($entry->id())->get('content'));
        }
    }

    public function test_cms_native_publish_fields_preserve_stale_form_version_and_reject_after_worker_commit(): void
    {
        $entry = $this->fencedEntry();
        $version = $entry->get('discussionbridge_cms_native_version');
        $field = $entry->blueprint()->field('discussionbridge_cms_native_version');
        $this->assertSame('hidden', $field->config()['visibility']);
        $values = $entry->blueprint()->fields()->addValues(['title' => 'Stale native editor title',
            'discussionbridge_cms_native_version' => $version])->process()->values();
        $this->assertSame($version, $values->get('discussionbridge_cms_native_version'));
        $entry->set('title', 'Current worker title')->saveQuietly();
        $current = $this->independentNativeEntry($entry);
        $current->merge($values);
        try {
            $current->save();
            $this->fail('Stale native form erased the newer worker revision.');
        } catch (CmsException $error) {
            $this->assertSame('native_ownership_conflict', $error->failureCode);
        }
        $this->assertSame('Current worker title', $this->independentNativeEntry($entry)->get('title'));
    }

    public function test_cms_cancelled_and_throwing_saves_release_the_native_lock(): void
    {
        $entry = $this->fencedEntry();
        $cancel = true;
        Event::listen(EntrySaving::class, function ($event) use ($entry, &$cancel) {
            if ($event->entry->id() === $entry->id() && $cancel) {
                $cancel = false;
                return false;
            }
            return null;
        });
        $entry->set('content', 'Cancelled native edit');
        $this->assertFalse($entry->save());
        $guard = app(NativeWriteGuard::class);
        $this->app->instance(NativeWriteGuard::class, new NativeWriteGuard);
        $current = $this->independentNativeEntry($entry);
        $current->set('content', 'After native cancellation');
        $this->assertTrue($current->saveQuietly());
        try {
            $current->set('content', 'Throw before native persistence');
            $this->insideNativeCommit(fn () => throw new \RuntimeException('Synthetic native commit interruption'), fn () => $current->saveQuietly());
            $this->fail('Native interruption was lost.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic native commit interruption', $error->getMessage());
        }
        $this->app->instance(NativeWriteGuard::class, $guard);
        $fresh = $this->independentNativeEntry($entry);
        $fresh->set('content', 'After native interruption');
        $this->assertTrue($fresh->saveQuietly());
        $this->assertSame('After native interruption', $this->independentNativeEntry($entry)->get('content'));
    }

    public function test_cms_reserved_canonical_path_rejects_unowned_native_entries_without_touching_other_entries(): void
    {
        $owned = $this->fencedEntry();
        $store = app(\CodeWorksLabs\DiscussionBridgeStatamic\Cms\PrivateState::class);
        $store->locked(function ($store) use ($owned) {
            $state = $store->read();
            $state['mappings']['reserved'] = ['native_id' => $owned->id(), 'mapping' => json_encode([
                'canonical_url' => $owned->absoluteUrl(), 'mapping_id' => $owned->get('discussionbridge_cms_mapping_id')])];
            $store->save($state);
        });
        $other = Entry::make()->collection('discussionbridge')->locale('default')->slug($owned->slug())
            ->published(true)->data(['title' => 'Unowned collision', 'content' => 'Unrelated native body']);
        try {
            $other->saveQuietly();
            $this->fail('Reserved owned native URL was taken over.');
        } catch (CmsException $error) {
            $this->assertSame('url_collision', $error->failureCode);
        }
        $this->assertSame('First native body', $this->independentNativeEntry($owned)->get('content'));
        $other->slug('unrelated-'.bin2hex(random_bytes(8)));
        $this->assertTrue($other->saveQuietly());
        $this->assertSame('Unrelated native body', Entry::find($other->id())->get('content'));
    }

    private function saveThrough($entry, string $path): void
    {
        match ($path) {
            'normal' => $entry->save(), 'quiet' => $entry->saveQuietly(), 'direct' => Entry::save($entry),
        };
    }

    private function independentNativeEntry($entry)
    {
        return $entry instanceof \Statamic\Eloquent\Entries\Entry
            ? \Statamic\Eloquent\Entries\Entry::fromModel($entry->model()->fresh())
            : Stache::store('entries')->store($entry->collectionHandle())
                ->makeItemFromFile($entry->path(), file_get_contents($entry->path()))->syncOriginal();
    }
}
