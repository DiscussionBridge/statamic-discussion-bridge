<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic;

use CodeWorksLabs\DiscussionBridgeStatamic\Cms\NativeRepositories;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\NativeWriteGuard;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\PrivateState;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\ToClosures;
use CodeWorksLabs\DiscussionBridgeStatamic\Listeners\GuardToNativeOperation;
use CodeWorksLabs\DiscussionBridgeStatamic\Listeners\AddBlueprintFields;
use CodeWorksLabs\DiscussionBridgeStatamic\Listeners\AddCmsRevisionField;
use CodeWorksLabs\DiscussionBridgeStatamic\Listeners\GuardCmsNativeSave;
use CodeWorksLabs\DiscussionBridgeStatamic\Listeners\PublishEntry;
use CodeWorksLabs\DiscussionBridgeStatamic\Listeners\ReleaseCmsNativeSave;
use Statamic\Events\EntryBlueprintFound;
use Statamic\Events\EntrySaved;
use Statamic\Events\EntrySaving;
use Statamic\Events\EntryDeleting;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $listen = [
        EntrySaved::class => [PublishEntry::class, ReleaseCmsNativeSave::class],
        EntrySaving::class => [GuardToNativeOperation::class, GuardCmsNativeSave::class],
        EntryDeleting::class => [GuardToNativeOperation::class],
        EntryBlueprintFound::class => [AddBlueprintFields::class, AddCmsRevisionField::class],
    ];

    protected $stylesheets = [__DIR__.'/../resources/css/control-panel.css'];

    protected $commands = [
        \CodeWorksLabs\DiscussionBridgeStatamic\Console\InstallDiscussionBridge::class,
        \CodeWorksLabs\DiscussionBridgeStatamic\Console\WorkDeliveries::class,
        \CodeWorksLabs\DiscussionBridgeStatamic\Console\ReconcileDeliveries::class,
        \CodeWorksLabs\DiscussionBridgeStatamic\Console\SyncPublications::class,
        \CodeWorksLabs\DiscussionBridgeStatamic\Console\PrepareStaticBuild::class,
        \CodeWorksLabs\DiscussionBridgeStatamic\Console\RefreshCmsCatalog::class,
        \CodeWorksLabs\DiscussionBridgeStatamic\Console\CheckNativeAttention::class,
        \CodeWorksLabs\DiscussionBridgeStatamic\Console\RestoreAttentionBaseline::class,
    ];

    protected $tags = [\CodeWorksLabs\DiscussionBridgeStatamic\Tags\DiscussionBridge::class];

    public function bootAddon(): void
    {
        $this->app->singleton(NativeWriteGuard::class);
        $this->app->singleton(PrivateState::class);
        $this->app->singleton(ToClosures::class);
        $this->registerRecoveryServices();
        $this->app->terminating(fn () => app(NativeWriteGuard::class)->releaseAll());
        $this->mergeConfigFrom(__DIR__.'/../config/discussionbridge.php', 'discussionbridge');
        $this->app->booted(fn () => NativeRepositories::install());
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'discussionbridge');
        $this->publishes(\CodeWorksLabs\DiscussionBridgeStatamic\Support\PresentationAssets::richPaths(), \CodeWorksLabs\DiscussionBridgeStatamic\Support\PresentationAssets::RICH_TAG);
        $this->publishes(\CodeWorksLabs\DiscussionBridgeStatamic\Support\PresentationAssets::templatePaths(), \CodeWorksLabs\DiscussionBridgeStatamic\Support\PresentationAssets::TEMPLATES_TAG);

        \Statamic\Facades\Utility::extend(function ($utilities): void {
            $utilities->register('discussionbridge')
                ->view('discussionbridge::utility', function (): array {
                    $secret = config('discussionbridge.secret_file');
                    $retryOptions = [];
                    $directory = config('discussionbridge.cms_state_directory') ?? storage_path('app/private/discussionbridge/cms-v4');
                    // A read-only utility view does not initialize a missing state directory.
                    if (is_string($directory) && is_dir($directory) && is_file($directory.'/state.json')) {
                        try { $retryOptions = app(\CodeWorksLabs\DiscussionBridgeStatamic\Cms\ToDelivery::class)->retryOptions(); }
                        catch (\Throwable) { /* Retained ambiguous/unavailable state is not retry eligibility. */ }
                    }
                    return [
                        'adapterVersion' => Version::VALUE,
                        'configured' => config('discussionbridge.enabled') && is_string($secret) && is_file($secret) && is_readable($secret)
                            && is_string(config('discussionbridge.connection_id')) && is_string(config('discussionbridge.forum_url'))
                            && is_string(config('discussionbridge.site_origin')),
                        'connectionId' => config('discussionbridge.connection_id'), 'forumUrl' => config('discussionbridge.forum_url'),
                        'siteOrigin' => config('discussionbridge.site_origin'),
                        'publicationCount' => \Statamic\Facades\Entry::query()->where('discussionbridge_cms_owned', true)->count(),
                        'lastResult' => \Illuminate\Support\Facades\Cache::get(\CodeWorksLabs\DiscussionBridgeStatamic\Http\Controllers\PublicationSyncController::LAST_RESULT_CACHE_KEY),
                        'staticGenerationAvailable' => \Composer\InstalledVersions::isInstalled('statamic/ssg'),
                        'retryOptions' => $retryOptions,
                    ];
                })
                ->title('DiscussionBridge')->navTitle('DiscussionBridge')->icon('earth')
                ->description('Synchronize authorized Discourse publications with Statamic.')
                ->routes(function ($router): void {
                    $router->post('synchronize', \CodeWorksLabs\DiscussionBridgeStatamic\Http\Controllers\PublicationSyncController::class)->name('synchronize');
                    $router->post('retry-invalid-field', \CodeWorksLabs\DiscussionBridgeStatamic\Http\Controllers\ToInvalidFieldRetryController::class)->name('retry-invalid-field');
                });
        });
    }

    /** Bind optional constructor dependencies explicitly: Laravel otherwise returns their null defaults. */
    protected function registerRecoveryServices(): void
    {
        $this->app->singleton(\CodeWorksLabs\DiscussionBridgeStatamic\Cms\AttentionRecovery::class);
        $this->app->singleton(\CodeWorksLabs\DiscussionBridgeStatamic\Cms\LocalStaticHooks::class);
    }
}
