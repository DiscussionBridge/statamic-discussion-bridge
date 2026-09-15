<section class="db-utility">
    <header class="db-utility__hero">
        <div class="db-utility__identity">
            <span class="db-utility__mark" aria-hidden="true">DB</span>
            <div>
                <h2>DiscussionBridge for Statamic</h2>
                <p class="db-utility__subtitle">Synchronize authorized Discourse publications with this Statamic site.</p>
            </div>
        </div>
        <span class="db-utility__status" data-ready="{{ $configured ? 'true' : 'false' }}">{{ $configured ? 'Connection ready' : 'Configuration needs attention' }}</span>
    </header>

    <div class="db-utility__metrics">
        <article class="db-utility__metric"><span>Adapter version</span><strong>{{ $adapterVersion }}</strong></article>
        <article class="db-utility__metric"><span>Native publications</span><strong>{{ $publicationCount }}</strong></article>
        <article class="db-utility__metric"><span>Last synchronization</span><strong>{{ $lastResult['completed_at'] ?? 'Not run yet' }}</strong></article>
    </div>

    <section class="db-utility__panel">
        <h3>Connection</h3>
        <div class="db-utility__details">
            <div><span>Connection ID</span><strong>{{ $connectionId ?: 'Not configured' }}</strong></div>
            <div><span>Discourse forum</span><strong>{{ $forumUrl ?: 'Not configured' }}</strong></div>
            <div><span>Statamic origin</span><strong>{{ $siteOrigin ?: 'Not configured' }}</strong></div>
            <div><span>Credential</span><strong>{{ $configured ? 'Protected and readable' : 'Missing or unreadable' }}</strong></div>
        </div>
    </section>

    @if ($lastResult)
        <section class="db-utility__result" data-success="{{ $lastResult['succeeded'] ? 'true' : 'false' }}" aria-live="polite">
            <strong>{{ $lastResult['succeeded'] ? 'Last synchronization completed' : 'Last synchronization needs attention' }}</strong>
            <div class="db-utility__counts">
                <span>Created {{ $lastResult['created'] }}</span><span>Updated {{ $lastResult['updated'] }}</span><span>Already current {{ $lastResult['unchanged'] }}</span><span>Skipped {{ $lastResult['skipped'] }}</span><span>Failed {{ $lastResult['failed'] }}</span>
            </div>
            @if (! empty($lastResult['errors']))<p>{{ implode(' ', $lastResult['errors']) }}</p>@endif
        </section>
    @endif

    <section class="db-utility__panel">
        <h3>Publish from Discourse</h3>
        <div class="db-utility__action">
            <form method="POST" action="{{ cp_route('utilities.discussionbridge.synchronize') }}">
                @csrf
                <button type="submit" class="btn-primary" @disabled(! $configured)>Synchronize now</button>
            </form>
            <p>Create or update Statamic entries authorized in The Bridge. Existing entries are updated in place; no duplicate pages are created.</p>
        </div>
    </section>

    <section class="db-utility__panel">
        <div class="db-utility__panel-heading">
            <h3>Static site generation</h3>
            <span class="db-utility__capability" data-active="{{ $staticGenerationAvailable ? 'true' : 'false' }}">
                {{ $staticGenerationAvailable ? 'Active for this profile' : 'Not active for this profile' }}
            </span>
        </div>

        @if ($lastGenerationResult)
            <section class="db-utility__result" data-success="{{ $lastGenerationResult['succeeded'] ? 'true' : 'false' }}" aria-live="polite">
                <strong>{{ $lastGenerationResult['succeeded'] ? 'Last static generation completed' : 'Last static generation needs attention' }}</strong>
                <div class="db-utility__counts">
                    <span>Prepared {{ $lastGenerationResult['prepared'] ? 'yes' : 'no' }}</span>
                    <span>Generated {{ $lastGenerationResult['generated'] ? 'yes' : 'no' }}</span>
                    <span>{{ $lastGenerationResult['completed_at'] }}</span>
                </div>
                @if (! empty($lastGenerationResult['error']))<p>{{ $lastGenerationResult['error'] }}</p>@endif
            </section>
        @endif

        <div class="db-utility__action">
            <form method="POST" action="{{ cp_route('utilities.discussionbridge.generate-static-site') }}">
                @csrf
                <button type="submit" class="btn-primary" @disabled(! $configured || ! $staticGenerationAvailable)>Regenerate static site</button>
            </form>
            <p>
                @if ($staticGenerationAvailable)
                    Prepare DiscussionBridge records, refresh authorized publications, and generate the public static site. Saved authoring changes are not public until generation completes.
                @else
                    This Statamic profile renders dynamically. Saved content does not require static generation.
                @endif
            </p>
        </div>
    </section>
</section>
