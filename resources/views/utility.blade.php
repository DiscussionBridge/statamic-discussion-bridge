<div class="card p-4">
    <div class="max-w-2xl">
        <h2 class="text-lg font-bold">Synchronize publications</h2>
        <p class="mt-2 text-gray-700">
            Create or update explicitly authorized Discourse publications in this Statamic site.
            Existing publication identities are preserved, and already-current entries are left unchanged.
        </p>

        <form method="POST" action="{{ cp_route('utilities.discussionbridge.synchronize') }}" class="mt-4">
            @csrf
            <button type="submit" class="btn-primary">Synchronize publications</button>
        </form>
    </div>
</div>
