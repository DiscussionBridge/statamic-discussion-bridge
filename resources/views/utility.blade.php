<style>
    .db-utility{--db-ink:#173b40;--db-teal:#0f766e;--db-mint:#e8f7f3;--db-border:#c8ded9;display:grid;gap:1rem;color:var(--db-ink)}
    .db-utility *{box-sizing:border-box}.db-utility__hero{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:1.25rem;border:1px solid var(--db-border);border-radius:.75rem;background:linear-gradient(135deg,var(--db-mint),#fff)}
    .db-utility__identity{display:flex;align-items:center;gap:1rem}.db-utility__mark{display:grid;width:3rem;height:3rem;place-items:center;border-radius:50%;color:#fff;background:var(--db-teal);font-weight:800}.db-utility h2,.db-utility p{margin:0}.db-utility__subtitle{margin-top:.25rem!important;color:#45666a}
    .db-utility__status{padding:.45rem .8rem;border:1px solid;border-radius:999px;font-weight:700;white-space:nowrap}.db-utility__status[data-ready="true"]{color:#075b3a;background:#dff7e9;border-color:#78c9a3}.db-utility__status[data-ready="false"]{color:#8a351d;background:#fff1ec;border-color:#e7a48f}
    .db-utility__metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.75rem}.db-utility__metric,.db-utility__panel{padding:1rem;border:1px solid var(--db-border);border-radius:.6rem;background:#fff}.db-utility__metric span{display:block;color:#557276}.db-utility__metric strong{display:block;margin-top:.25rem;font-size:1.35rem;color:var(--db-teal);overflow-wrap:anywhere}
    .db-utility__panel{display:grid;gap:.85rem}.db-utility__panel h3{margin:0;font-size:1.05rem}.db-utility__details{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.75rem}.db-utility__details div{min-width:0}.db-utility__details span,.db-utility__details strong{display:block}.db-utility__details span{font-size:.85rem;color:#557276}.db-utility__details strong{margin-top:.2rem;overflow-wrap:anywhere}
    .db-utility__result{padding:.85rem 1rem;border-left:.35rem solid #159b63;border-radius:.4rem;background:#e7f8ef}.db-utility__result[data-success="false"]{border-left-color:#bf442f;background:#fff1ec}.db-utility__counts{display:flex;flex-wrap:wrap;gap:.5rem 1rem;margin-top:.5rem}.db-utility__counts span{white-space:nowrap}.db-utility__action{display:flex;align-items:center;gap:1rem;flex-wrap:wrap}.db-utility__action p{max-width:46rem;color:#45666a}
    @media(max-width:700px){.db-utility__hero{align-items:flex-start;flex-direction:column}.db-utility__metrics,.db-utility__details{grid-template-columns:1fr}}
</style>

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
        <h3>Synchronize publications</h3>
        <div class="db-utility__action">
            <form method="POST" action="{{ cp_route('utilities.discussionbridge.synchronize') }}">
                @csrf
                <button type="submit" class="btn-primary" @disabled(! $configured)>Synchronize publications</button>
            </form>
            <p>Create or update explicitly authorized Discourse publications. Existing identities are preserved, and an already-current retry refreshes Statamic's presentation state.</p>
        </div>
    </section>
</section>
