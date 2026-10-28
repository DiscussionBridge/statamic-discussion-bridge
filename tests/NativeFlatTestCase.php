<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use Statamic\Facades\Collection;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;

abstract class NativeFlatTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $fixture = dirname(__DIR__).'/.tmp/native-flat-'.bin2hex(random_bytes(8));
        mkdir($fixture.'/collections', 0700, true);
        mkdir($fixture.'/structures', 0700, true);
        Site::setSites(['default' => ['name' => 'Isolated Flat fixture', 'url' => 'https://statamic.example', 'locale' => 'en_US']]);
        Stache::store('collections')->directory($fixture.'/collections');
        Stache::store('entries')->directory($fixture.'/collections');
        Stache::store('collection-trees')->directory($fixture.'/structures');
        Collection::make('discussionbridge')->routes('/discussionbridge/{slug}')->template('native-publication')->save();
    }
}
