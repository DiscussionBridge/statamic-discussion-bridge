<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic;

use CodeWorksLabs\DiscussionBridgeStatamic\Console\RetryDelivery;
use CodeWorksLabs\DiscussionBridgeStatamic\Console\InstallDiscussionBridge;
use CodeWorksLabs\DiscussionBridgeStatamic\Console\ReconcileDeliveries;
use CodeWorksLabs\DiscussionBridgeStatamic\Console\PrepareStaticBuild;
use CodeWorksLabs\DiscussionBridgeStatamic\Console\WorkDeliveries;
use CodeWorksLabs\DiscussionBridgeStatamic\Console\SyncPublications;
use CodeWorksLabs\DiscussionBridgeStatamic\Listeners\AddBlueprintFields;
use CodeWorksLabs\DiscussionBridgeStatamic\Listeners\PublishEntry;
use CodeWorksLabs\DiscussionBridgeStatamic\Http\Controllers\PublicationSyncController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use CodeWorksLabs\DiscussionBridgeStatamic\Tags\DiscussionBridge;
use Statamic\Events\EntrySaved;
use Statamic\Events\EntryBlueprintFound;
use Statamic\Providers\AddonServiceProvider;
use Statamic\Facades\Utility;

class ServiceProvider extends AddonServiceProvider
{
    protected $stylesheets = [
        __DIR__.'/../resources/css/control-panel.css',
    ];

    protected $listen = [
        EntrySaved::class => [PublishEntry::class],
        EntryBlueprintFound::class => [AddBlueprintFields::class],
    ];

    protected $tags = [
        DiscussionBridge::class,
    ];

    protected $commands = [
        InstallDiscussionBridge::class,
        RetryDelivery::class,
        ReconcileDeliveries::class,
        PrepareStaticBuild::class,
        WorkDeliveries::class,
        SyncPublications::class,
    ];

    public function bootAddon(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/discussionbridge.php', 'discussionbridge');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'discussionbridge');

        Utility::extend(function ($utilities): void {
            $utilities->register('discussionbridge')
                ->view('discussionbridge::utility', function (): array {
                    $connectionId = (string) config('discussionbridge.connection_id', '');
                    $forumUrl = (string) config('discussionbridge.forum_url', '');
                    $siteOrigin = (string) config('discussionbridge.site_origin', '');
                    $secretFile = (string) config('discussionbridge.secret_file', '');
                    $configured = (bool) config('discussionbridge.enabled')
                        && $connectionId !== ''
                        && $forumUrl !== ''
                        && $siteOrigin !== ''
                        && $secretFile !== ''
                        && is_file($secretFile)
                        && is_readable($secretFile);
                    return [
                        'adapterVersion' => Version::VALUE,
                        'configured' => $configured,
                        'connectionId' => $connectionId,
                        'forumUrl' => $forumUrl,
                        'siteOrigin' => $siteOrigin,
                        'publicationCount' => Schema::hasTable('discussionbridge_publications')
                            ? DB::table('discussionbridge_publications')->count()
                            : 0,
                        'lastResult' => Cache::get(PublicationSyncController::LAST_RESULT_CACHE_KEY),
                    ];
                })
                ->title('DiscussionBridge')
                ->navTitle('DiscussionBridge')
                ->icon('earth')
                ->description('Synchronize authorized Discourse publications with Statamic.')
                ->routes(function ($router): void {
                    $router->post('synchronize', PublicationSyncController::class)->name('synchronize');
                });
        });
    }
}
