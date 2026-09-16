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

</section>
