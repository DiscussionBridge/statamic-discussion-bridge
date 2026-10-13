<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\PublicationPresenter;
use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\StandalonePresenter;
use Illuminate\Support\Facades\DB;
use Mockery;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;

class PublicationPresenterTest extends TestCase
{
    public function test_existing_native_mode_selects_the_actual_presenter_and_withdrawal_renders_nothing(): void
    {
        DB::table('discussionbridge_publications')->insert([
            'resource_id' => '11111111-1111-4111-8111-111111111111', 'entry_id' => 'native-entry',
            'canonical_url' => 'https://statamic.example/discussionbridge/source', 'canonical_url_digest' => hash('sha256', 'https://statamic.example/discussionbridge/source'),
            'source_revision' => 'post:149:version:1', 'topic_id' => 53, 'topic_url' => 'https://forum.example/t/source/53', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $mode = 'simple';
        $status = 'active';
        $entry = Mockery::mock(EntryContract::class);
        $entry->shouldReceive('get')->with('discussionbridge_mode')->andReturnUsing(function () use (&$mode) { return $mode; });
        $entry->shouldReceive('get')->with('discussionbridge_source_status')->andReturnUsing(function () use (&$status) { return $status; });
        Entry::shouldReceive('find')->with('native-entry')->andReturn($entry);
        $standalone = Mockery::mock(StandalonePresenter::class);
        $standalone->shouldReceive('simple')->once()->with(53)->andReturn('<p>Native Simple</p>');
        $standalone->shouldReceive('fullTopic')->once()->with(53)->andReturn('<p>Native Full</p>');
        $this->app->instance(StandalonePresenter::class, $standalone);
        $presenter = app(PublicationPresenter::class);
        $this->assertSame('<p>Native Simple</p>', $presenter->render('native-entry'));
        $mode = 'full';
        $this->assertSame('<p>Native Full</p>', $presenter->render('native-entry'));
        $mode = 'interactive';
        $this->assertStringContainsString('"fullApp":true', $presenter->render('native-entry'));
        $status = 'withdrawn';
        $this->assertSame('', $presenter->render('native-entry'));
    }
}
