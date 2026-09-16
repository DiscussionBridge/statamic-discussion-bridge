<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Delivery\DeliveryEnqueuer;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;

class LargeSiteReconcileBenchmarkTest extends TestCase
{
    private ?string $fixtureRoot = null;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $this->fixtureRoot = sys_get_temp_dir().'/discussionbridge-statamic-large-site-'.Str::random(16);
        $app['config']->set('statamic.stache.stores.collections.directory', $this->fixtureRoot.'/collections');
        $app['config']->set('statamic.stache.stores.entries.directory', $this->fixtureRoot.'/collections');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if ($this->fixtureRoot !== null) {
            $this->removeFixture($this->fixtureRoot);
        }
    }

    public function test_reconciliation_census_on_a_large_flat_collection(): void
    {
        if (getenv('DISCUSSIONBRIDGE_STATAMIC_BENCHMARK') !== '1') {
            $this->markTestSkipped('Run explicitly with DISCUSSIONBRIDGE_STATAMIC_BENCHMARK=1.');
        }

        $count = (int) (getenv('DISCUSSIONBRIDGE_STATAMIC_BENCHMARK_PAGES') ?: 1000);
        $this->assertGreaterThanOrEqual(1, $count);
        $this->assertLessThanOrEqual(10000, $count);

        Collection::make('pages')->routes(['default' => '/{slug}'])->save();
        $entriesDirectory = $this->fixtureRoot.'/collections/pages';
        if (! is_dir($entriesDirectory)) {
            mkdir($entriesDirectory, 0700, true);
        }
        for ($index = 1; $index <= $count; $index++) {
            $number = str_pad((string) $index, 5, '0', STR_PAD_LEFT);
            file_put_contents($entriesDirectory.'/page-'.$number.'.md', "---\nid: ".Str::uuid()."\ntitle: Page {$number}\ndiscussionbridge_publish: false\n---\n\n# Page {$number}\n\nRepresentative static content.\n");
        }
        Stache::clear();

        $mode = getenv('DISCUSSIONBRIDGE_STATAMIC_BENCHMARK_MODE') ?: 'reconcile';
        $this->assertContains($mode, ['get', 'reconcile']);
        $started = hrtime(true);
        if ($mode === 'get') {
            $entries = Entry::query()->whereIn('collection', ['pages'])->get();
            $this->assertCount($count, $entries);
            $enqueuer = app(DeliveryEnqueuer::class);
            $enqueued = 0;
            foreach ($entries as $entry) {
                $enqueued += $enqueuer->enqueue($entry) ? 1 : 0;
            }
            $this->assertSame(0, $enqueued);
        } else {
            $this->artisan('discussionbridge:reconcile')
                ->expectsOutputToContain('"scanned":'.$count)
                ->assertSuccessful();
        }
        $this->assertSame(0, DB::table('discussionbridge_deliveries')->count());
        $elapsedMs = (hrtime(true) - $started) / 1_000_000;

        fwrite(STDOUT, json_encode([
            'pages' => $count,
            'mode' => $mode,
            'elapsed_ms' => round($elapsedMs),
            'peak_memory_bytes' => memory_get_peak_usage(true),
        ], JSON_THROW_ON_ERROR).PHP_EOL);
    }

    private function removeFixture(string $root): void
    {
        if (! is_dir($root) || ! str_starts_with($root, sys_get_temp_dir().'/discussionbridge-statamic-large-site-')) {
            return;
        }
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $root.'/'.$entry;
            if (is_dir($path)) {
                $this->removeFixture($path);
            } else {
                unlink($path);
            }
        }
        rmdir($root);
    }
}
