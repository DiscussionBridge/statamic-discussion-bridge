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
                <span>Processed {{ $lastResult['processed'] }}</span><span>Publicly verified {{ $lastResult['verified'] }}</span><span>Receiver attention {{ $lastResult['attention'] }}</span><span>Local content limitations {{ $lastResult['local_issues'] ?? 0 }}</span>
                <span>Receiver retry wait {{ $lastResult['retry_wait'] ?? 0 }}</span><span>Attempt failures {{ $lastResult['attempt_failures'] ?? 0 }}</span>
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

    @if (! empty($retryOptions))
        <section class="db-utility__panel">
            <h3>Retry a refused publication</h3>
            <p>After correcting the cause of an invalid-field refusal, retry the unchanged article. Each action keeps the refused request and checks the current source and connection before queuing one linked retry.</p>
            @foreach ($retryOptions as $retry)
                <form method="POST" action="{{ cp_route('utilities.discussionbridge.retry-invalid-field') }}">
                    @csrf
                    <input type="hidden" name="delivery_id" value="{{ $retry['delivery_id'] }}">
                    <input type="hidden" name="request_identity" value="{{ $retry['request_identity'] }}">
                    <span>Entry {{ $retry['entry_id'] }}</span>
                    <button type="submit" class="btn-primary">Retry publication</button>
                </form>
            @endforeach
        </section>
    @endif

    <section class="db-utility__panel">
        <div class="db-utility__panel-heading">
            <h3>Static site generation</h3>
            <span class="db-utility__capability" data-active="{{ $staticGenerationAvailable ? 'true' : 'false' }}">
                {{ $staticGenerationAvailable ? 'Managed by Statamic SSG' : 'Not active for this profile' }}
            </span>
        </div>

        <p>
            @if ($staticGenerationAvailable)
                Statamic-authored pages use the configured native generator. Authorized publications from Discourse use protected generation, configured deployment and restoration hooks, and actual public verification.
            @else
                This Statamic profile renders dynamically. Saved content does not require static generation.
            @endif
        </p>
    </section>
</section>
