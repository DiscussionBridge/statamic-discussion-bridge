<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;

abstract class NativeDatabaseTestCase extends TestCase
{
    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);
        $root = dirname((new \ReflectionClass(\Statamic\Eloquent\ServiceProvider::class))->getFileName(), 2);
        $app['config']->set('statamic.eloquent-driver', require $root.'/config/eloquent-driver.php');
        $app['config']->set('statamic.eloquent-driver.entries.driver', 'eloquent');
        $app['config']->set('statamic.eloquent-driver.entries.model', \Statamic\Eloquent\Entries\UuidEntryModel::class);
    }

    protected function getPackageProviders($app)
    {
        return array_merge(parent::getPackageProviders($app), [\Statamic\Eloquent\ServiceProvider::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $root = dirname((new \ReflectionClass(\Statamic\Eloquent\ServiceProvider::class))->getFileName(), 2);
        $this->loadMigrationsFrom($root.'/database/migrations/entries/2024_03_07_100000_create_entries_table_with_string_ids.php');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $fixture = dirname(__DIR__).'/.tmp/native-db-'.bin2hex(random_bytes(8));
        mkdir($fixture.'/collections', 0700, true);
        mkdir($fixture.'/structures', 0700, true);
        Site::setSites(['default' => ['name' => 'Native DB fixture', 'url' => 'https://statamic.example', 'locale' => 'en_US']]);
        Stache::store('collections')->directory($fixture.'/collections');
        Stache::store('collection-trees')->directory($fixture.'/structures');
        Collection::make('discussionbridge')->routes('/discussionbridge/{slug}')->template('native-publication')->save();
        $this->assertInstanceOf(\Statamic\Eloquent\Entries\EntryRepository::class, Entry::getFacadeRoot());
    }
}
