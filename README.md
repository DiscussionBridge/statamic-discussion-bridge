# DiscussionBridge for Statamic

For the public GitHub Alpha, install the tagged Composer package into an
existing Statamic 6 application:

```sh
composer config repositories.discussionbridge vcs https://github.com/DiscussionBridge/statamic-discussion-bridge.git
composer require codeworkslabs/statamic-discussion-bridge:0.2.0-alpha.38
php please discussionbridge:install
```

The GitHub VCS repository is the current distribution source; this package is
not yet listed on Packagist or the Statamic Marketplace. Do not substitute a
mutable `main` checkout for the tagged version when reproducing an Alpha
installation. The guided installer handles the profile-specific connection
and protected credential after Composer installs the addon.

This Statamic 6 addon connects an explicitly opted-in, durably published entry
to the DiscussionBridge Bridge Record contract. The same package serves the
Alpha Statamic Flat, DB, and SSG profiles; each installation has its own
origin, Content Connection, secret, operational table, worker, content and
rollback package.

## Behavior

- `EntrySaved` performs only a local, idempotent enqueue into the addon-owned
  `discussionbridge_deliveries` table. It never calls Discourse during the
  content-save request.
- `discussionbridge:work --limit=25` atomically claims and processes a bounded
  batch. A root-owned systemd timer should invoke it under the owning Statamic
  application user.
- The worker renders the entry's published Markdown into a bounded HTML
  snapshot. The receiving topic therefore contains meaningful source content
  plus canonical attribution; missing or oversized content fails closed.
- `discussionbridge:retry <entry-id>` explicitly requeues one failed,
  cancelled or reconciliation-required item without changing its stable
  external identity or original correlation ID. The additional
  `--delivered` switch is required to exercise an already successful identity;
  the returned resource/topic tuple must remain exact.
- `discussionbridge:reconcile` traverses the configured collections in bounded
  entry batches and enqueues any eligible entry missing addon state. Statamic's
  own Stache index remains a separate memory and build-time cost.
- `discussionbridge:ssg-prepare` is the static-build gate. It reconciles the
  configured collections, drains a bounded number of deliveries, and fails
  closed while any pending, processing, failed or reconciliation-required
  record remains. Run it immediately before `php please ssg:generate`.
- `discussionbridge:refresh-platform-catalog` reads the installation's actual
  configured collections, attached taxonomies and terms, and publish-capable
  Statamic users, then uploads that bounded nonsecret inventory to The Bridge.
  The receiver owns category/tag/author mapping; the addon never guesses a
  destination that the operator has not selected.
- `discussionbridge:sync-publications` consumes only From Discourse bindings
  carrying explicit native-materialization authority. It creates or updates a
  genuine entry in the mapped collection at its authorized native route, applies
  mapped taxonomy terms and the selected native author, and records the exact
  Discourse post revision in an addon-owned table and leaves presentation-only
  records alone. Root publication is the default; an optional source path must
  identify an existing Statamic parent destination.
  Flat, DB and SSG use the same package but retain independent connections,
  storage and native entry identities. Exact retries are unchanged;
  destination or resource collisions fail closed. Updated entries explicitly
  invalidate Statamic's affected public-page cache and the addon's bounded
  record cache before synchronization reports success.
- `discussionbridge:sync-publication-work --limit=20` is the steady-state
  incremental worker for Statamic Flat and DB after the initial synchronization.
  It claims receiver-owned work with an exact five-minute lease, processes only
  the identified topic or withdrawal, acknowledges against that lease, and
  reports bounded failures to the receiver's shared operator queue. It does not
  repeat the complete Bridge Record census. The original Discourse topic
  creation time becomes the native entry date; later first-post edits update
  the same entry without changing that publication date.
- Statamic SSG uses a protected two-phase lifecycle. Run
  `discussionbridge:ssg-prepare-publication-work`, then the existing
  `discussionbridge:ssg-prepare` delivery gate and `ssg:generate`; deploy the
  generated estate; and run `discussionbridge:ssg-finalize-publication-work`.
  Finalize performs a bounded public HTTPS check for the exact resource and
  publication-revision markers before acknowledging the receiver lease.
  `discussionbridge:ssg-abort-publication-work` restores every unacknowledged
  native change and returns it to the shared attention queue. The protected,
  atomically replaced transaction journal survives interrupted builds and must
  never be copied into generated output. The dynamic publication worker must
  not be scheduled for the SSG profile.
- The Statamic Control Panel exposes the same operation under
  **Utilities → DiscussionBridge**. Its permissioned **Synchronize
  publications** action prevents concurrent runs and reports created, updated,
  already-current, skipped and failed totals. The command remains available
  for automation, recovery and SSG build gates.
- The same utility serves Flat, DB and SSG only for DiscussionBridge
  publication synchronization. Native Statamic SSG generation remains a
  separate platform operation, never implied by a successful sync.
- `{{ discussionbridge:record resource="{discussionbridge_resource_id}" }}`
  performs a bounded authenticated server-side pull, sanitizes the cooked first
  post, builds native page navigation, and presents the same topic's replies in
  a credential-free Interactive frame.
- `{{ discussionbridge:discussion entry="{id}" }}` presents the healthy topic owned by the
  current To Discourse entry. Both tags use the same centered DiscussionBridge
  credit and responsive discussion treatment.
- `{{ discussionbridge:article entry="{id}" }}` renders a publishing entry with a
  native heading-derived **On this page** navigation.
- `{{ discussionbridge:simple topic="{discussionbridge_topic_id}" }}` renders
  the public replies from one ordinary Discourse topic as native Statamic
  markup. The generated or server-rendered reply snapshot is an immediate
  no-JavaScript/failure fallback; a credential-free browser refresh retrieves
  current public replies on every page load. It uses no Content Connection
  credential and does not create or read a Bridge Record.
- `{{ discussionbridge:full canonical="{discussionbridge_canonical_url}" }}`
  uses Discourse Core's standard canonical-URL comments embed. It is likewise
  plugin-free: there is no topic-ID claim, receiver credential, or Bridge
  Record request.

The addon adds two ephemeral blueprint fields to configured collection entries:
`discussionbridge_publish` for To Discourse opt-in and
`discussionbridge_resource_id` for From Discourse presentation. Field values
persist through Statamic's active repository, while delivery state remains in
the addon table. The optional `discussionbridge_mode` and
`discussionbridge_topic_id` fields select plugin-free Simple or Full
presentation; Full resolves from the page's canonical URL.

## Configuration

Run the native guided installer from the Statamic application root:

```shell
php please discussionbridge:install
```

It prompts for the forum and site origins, Content Connection ID, hidden
connection secret, optional lane, collections, native publication service
author ID and source author. It stores the
secret outside the public webroot with owner-only permissions, updates `.env`
atomically after creating a timestamped backup, runs migrations, clears cached
configuration, uploads the current platform catalog, and verifies the
connection without creating content. A
successful verification records the addon identity, version and last-seen time
on The Bridge. The installer also publishes the addon's versioned Control Panel
stylesheet through Statamic's normal addon asset mechanism.

After updating an existing installation, refresh the published Control Panel
asset from the application root:

```shell
php please vendor:publish --tag=statamic-discussion-bridge --force
```

The resulting protected environment contains these nonsecret values:

```dotenv
DISCUSSIONBRIDGE_ENABLED=true
DISCUSSIONBRIDGE_FORUM_URL=https://sandbox-forum.discussionbridge.dev
DISCUSSIONBRIDGE_SITE_ORIGIN=https://statamic-flat.sandbox.discussionbridge.dev
DISCUSSIONBRIDGE_CONNECTION_ID=dbc_000000000000000000000000
DISCUSSIONBRIDGE_SECRET_FILE=/absolute/application/storage/app/discussionbridge/connection-secret
DISCUSSIONBRIDGE_LANE=statamic-flat-alpha
DISCUSSIONBRIDGE_COLLECTIONS=pages
DISCUSSIONBRIDGE_NATIVE_AUTHOR_ID=publisher@example.com
DISCUSSIONBRIDGE_SSG_TRANSACTION_FILE=/absolute/application/storage/app/discussionbridge/ssg-publication-transaction.json
DISCUSSIONBRIDGE_SOURCE_AUTHOR_NAME="Statamic Flat Demo"
DISCUSSIONBRIDGE_SOURCE_AUTHOR_PROFILE_URL=https://statamic-flat.demo.discussionbridge.dev/
```

`DISCUSSIONBRIDGE_NATIVE_AUTHOR_ID` identifies the real publish-capable
Statamic user used when the receiver mapping selects the service author. The
source-author values are per-profile operator settings reported to The
Bridge and can be mapped there to the selected Discourse user. The secret file
must be outside the webroot and readable only by the owning
application group. Flat and DB must never share a connection secret or secret
directory.

## Static site generation

Statamic SSG is a third installed profile of this same addon, not a separate
adapter. Its authoring/build application owns an independent origin,
connection, secret and delivery database. Simple comments and From The Bridge
content are rendered into the generated files at build time. Simple then
refreshes its public replies in the browser, so new comments do not require a
new static build; the generated snapshot remains the fallback. Full comments
and Publishing through The Bridge retain their credential-free live Discourse
frames in the generated HTML.

The release build order is strict:

```shell
php please discussionbridge:refresh-platform-catalog
php please discussionbridge:ssg-prepare-publication-work --limit=20
php please discussionbridge:ssg-prepare
php please ssg:generate
# deploy the exact generated estate
php please discussionbridge:ssg-finalize-publication-work
```

The publication-work preparation command writes an owner-protected transaction
journal before changing native entries. It deliberately leaves each receiver
item leased and unacknowledged. After deployment, finalize fetches each exact
public canonical URL without credentials or redirects and requires the prepared
resource and publication-revision markers before acknowledging it. A failed
build or abandoned candidate must be returned with
`php please discussionbridge:ssg-abort-publication-work`; abort restores the
prior native state and reports each item to the shared operator attention queue.
An interrupted finalize is safely resumable from its journal. Never delete or
edit that journal by hand, and never run prepare while one exists.

Regenerate Statamic-authored pages through the native Statamic SSG workflow.
The Control Panel synchronization utility remains appropriate for Flat and DB;
it must not replace the protected two-phase commands on the SSG profile. No
successful preparation or native save implies that the public static site was
regenerated or deployed.

Do not deploy output when the preparation command fails. A generated site may
be hosted without PHP, Statamic, a connection secret or an adapter worker; only
the protected authoring/build application performs receiver-authenticated
requests.

### Large-site verification

The opt-in `LargeSiteReconcileBenchmarkTest` creates a temporary file-backed
collection and measures the addon's reconciliation pass. It is skipped during
the ordinary test suite. On PowerShell, run it with:

```powershell
$env:DISCUSSIONBRIDGE_STATAMIC_BENCHMARK = '1'
$env:DISCUSSIONBRIDGE_STATAMIC_BENCHMARK_PAGES = '1000'
php vendor/bin/phpunit tests/LargeSiteReconcileBenchmarkTest.php --colors=never
Remove-Item Env:DISCUSSIONBRIDGE_STATAMIC_BENCHMARK
Remove-Item Env:DISCUSSIONBRIDGE_STATAMIC_BENCHMARK_PAGES
```

The separate `scripts/prepare-ssg-benchmark.php` can populate a disposable
full Statamic application for measuring Statamic's native `ssg:generate`. It
requires an explicit `.discussionbridge-benchmark-sandbox` marker and refuses
to overwrite an existing benchmark collection. These are engineering fixtures,
not release or deployment commands. A native SSG timing without this addon
installed does not prove the end-to-end DiscussionBridge build time.

Install the package through Composer, run `php please discussionbridge:install`,
and add the presentation tag to the selected Antlers template. Before
installation, preserve the profile-specific backup set described in the
DiscussionBridge successor checkpoint.
