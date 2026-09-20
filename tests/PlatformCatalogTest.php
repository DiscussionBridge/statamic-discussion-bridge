<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Publication\PlatformCatalog;
use Statamic\Facades\Collection;
use Statamic\Facades\User;

class PlatformCatalogTest extends TestCase
{
    public function test_it_reports_real_configured_collections_and_publish_capable_authors(): void
    {
        Collection::make('pages')
            ->title('Pages')
            ->routes(['default' => '/articles/{slug}'])
            ->save();
        User::make()
            ->id('statamic-service-user')
            ->email('publisher@example.com')
            ->data(['name' => 'Publishing Desk', 'super' => true])
            ->save();

        $catalog = app(PlatformCatalog::class)->build();

        $this->assertSame('statamic', $catalog['platform']);
        $this->assertSame('pages', $catalog['containers'][0]['id']);
        $this->assertSame('/articles/', $catalog['containers'][0]['path']);
        $this->assertSame('user:statamic-service-user', $catalog['service_author_id']);
        $this->assertSame('Publishing Desk', $catalog['authors'][0]['label']);
        $this->assertSame('/articles/forum-topic-53', app(PlatformCatalog::class)->canonicalPath('pages', 'forum-topic-53'));
    }
}
