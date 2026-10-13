# DiscussionBridge for Statamic

```sh
git clone https://github.com/DiscussionBridge/statamic-discussion-bridge.git
```

This Statamic 6 addon connects an explicitly opted-in, durably published entry
to the DiscussionBridge Bridge Record contract. The same package serves the
Alpha Statamic Flat and Statamic DB profiles; each installation has its own
origin, Content Connection, secret, operational table, worker, content and
rollback package.

The local candidate is `0.3.0-alpha.1`, using the additive central
[`core-publication-extension.json`](../adapter-contract/core-publication-extension.json).
Negotiated From inventory and detail use `publication_contract=0.3.0-alpha.1`.
Metadata is bounded separately from complete source HTML, which is retrieved in
32 KiB chunks and verified against resource, edition, offsets, bytes, SHA-256
and UTF-8 before native persistence. A changed edition during retrieval refreshes
the detail, with at most three attempts. The same edition skips content retrieval;
a fresh permission check precedes reuse of cached display content.

To exports preserve the complete portable source digest and length while sending
a valid HTML excerpt plus canonical Read More when necessary. Source HTML stays
within 48 KiB and the complete serialized request within 64 KiB. An accepted
source revision is persisted; changed native editions queue an update against
the previous accepted revision. Source creation and update dates remain distinct,
and unknown native timestamps are explicit null. File checkout, binding and sync
times are never substituted for source dates.

Existing native entry identity, canonical mapping, custom data and explicit
`discussionbridge_mode` survive updates. New entries can seed the selected
binding mode. A changed Discourse slug is accepted only for the same validated
topic ID. Explicit owned withdrawal unpublishes that same native entry while
retaining private recovery content; restoration repairs its existing identity
and prior publication choice. Omitted records or failed requests do not imply
withdrawal. Static withdrawal still requires native generation and verification
that public output no longer contains the withdrawn page.

## Behavior

- `EntrySaved` performs only a local, idempotent enqueue into the addon-owned
  `discussionbridge_deliveries` table. It never calls Discourse during the
  content-save request.
- `discussionbridge:work --limit=25` atomically claims and processes a bounded
  batch. A root-owned systemd timer should invoke it under the owning Statamic
  application user.
- The worker renders complete portable source HTML and sends the bounded
  source excerpt when needed, with full-source edition metadata and canonical
  attribution. Missing or invalid source content fails closed.
- `discussionbridge:retry <entry-id>` explicitly requeues one failed,
  cancelled or reconciliation-required item without changing its stable
  external identity or original correlation ID. The additional
  `--delivered` switch is required to exercise an already successful identity;
  the returned resource/topic tuple must remain exact.
- `discussionbridge:reconcile` scans the configured collections and enqueues
  any eligible entry missing addon state.
- `discussionbridge:ssg-prepare` is the static-build gate. It reconciles the
  configured collections, drains a bounded number of deliveries, and fails
  closed while any pending, processing, failed or reconciliation-required
  record remains. Run it immediately before `php please ssg:generate`.
- `discussionbridge:sync-publications` consumes only From Discourse bindings
  carrying explicit native-materialization authority. It creates or updates a
  genuine entry beneath `/discussionbridge`, records the exact Discourse post
  revision in an addon-owned table and leaves presentation-only records alone.
  Flat, DB and SSG use the same package but retain independent connections,
  storage and native entry identities. Exact retries are unchanged;
  destination or resource collisions fail closed. Updated entries explicitly
  invalidate Statamic's affected public-page cache and the addon's bounded
  record cache before synchronization reports success.
- The Statamic Control Panel exposes the same operation under
  **Utilities → DiscussionBridge**. Its permissioned **Synchronize
  publications** action prevents concurrent runs and reports created, updated,
  already-current, skipped and failed totals. The command remains available
  for automation, recovery and SSG build gates.
- The same utility always explains the **Static site generation** capability.
  Statamic SSG identifies generation as managed by Statamic SSG. Flat and DB
  show the capability as inactive because those profiles render saved content
  dynamically. DiscussionBridge publication synchronization remains separate
  from native Statamic static generation.
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
connection secret, optional lane, collections and source author. It stores the
secret outside the public webroot with owner-only permissions, updates `.env`
atomically after creating a timestamped backup, runs migrations, clears cached
configuration, and verifies the connection without creating content. A
successful verification records the addon identity, version and last-seen time
on The Bridge. The installer also publishes the addon's versioned Control Panel
stylesheet through Statamic's normal addon asset mechanism.

Rich diagram rendering and additive templates are separate operator choices.
The package includes optional Mermaid diagrams and fenced `math` rendering,
but normal installation publishes neither its public script/CSS nor the entry fragments and does not enable
it. To select both capabilities during installation, use:

```shell
php please discussionbridge:install --rich-content --templates
```

`--rich-content` writes `DISCUSSIONBRIDGE_RICH_CONTENT="true"` and publishes the
bundled renderer. Native `discussionbridge:article` output then includes its
script and local math stylesheet, with fingerprints of the package assets. The default native config
`discussionbridge.rich_content` is unset. A fresh entry emits no renderer until
selected. An existing native publication with the previously installed package
script keeps that activation during rendering and source updates until the
operator explicitly selects or disables it. Re-running installation without this
flag preserves an existing selection; it does not reset it. To use the site's
own equivalent, set `DISCUSSIONBRIDGE_RICH_CONTENT=false`, supply that renderer through the
site's layout, and clear cached configuration after changing the environment.
Explicit false suppresses the package's legacy script. Received source content
is sanitized as HTML; custom renderer scripts belong in the operator's layout
and assets, rather than in received source HTML. Ordinary Statamic-authored
content and operator-owned layouts remain under the site's control.

`--templates` publishes the two fragments beneath
`resources/views/vendor/discussionbridge/`; it changes neither the site's entry
template nor its theme. Include the selected fragment once in the entry layout,
using `{{ partial:vendor/discussionbridge/entry }}` or the `entry-ssg` equivalent,
or merge its supported tag calls into your own template. Publishing a fragment
does not prove that the site's layout invokes it.

The capabilities can also be published independently through native tags:

```shell
php please vendor:publish --tag=statamic-discussion-bridge-rich-content
php please vendor:publish --tag=statamic-discussion-bridge-templates
```

These optional paths preserve existing files. Publishing the renderer alone
does not activate it; select `DISCUSSIONBRIDGE_RICH_CONTENT=true` separately.
Installer and SSG preparation verify exact bundled bytes only when that
renderer is selected. A stale or customized selected renderer stops that
verification without overwriting it. After preserving any operator changes,
explicitly refresh the bundled asset with
`php please vendor:publish --tag=statamic-discussion-bridge-rich-content --force`,
or disable the bundled selection and retain your equivalent. Customized
partials are likewise preserved; refresh them only by explicit operator choice.

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
DISCUSSIONBRIDGE_SOURCE_AUTHOR_NAME="Statamic Flat Demo"
DISCUSSIONBRIDGE_SOURCE_AUTHOR_PROFILE_URL=https://statamic-flat.demo.discussionbridge.dev/
```

The source-author values are per-profile operator settings reported to The
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
php please discussionbridge:ssg-prepare
php please ssg:generate
```

Regenerate Statamic-authored pages through the native Statamic SSG workflow.
Use **Utilities → DiscussionBridge → Synchronize now** only to create or update
authorized publications from The Bridge before a subsequent native generation.
The utility never implies that saving or synchronizing content has already
regenerated the public static site.

Do not deploy output when the preparation command fails. A generated site may
be hosted without PHP, Statamic, a connection secret or an adapter worker; only
the protected authoring/build application performs receiver-authenticated
requests.

When the bundled rich renderer is selected, preparation publishes and verifies
`public/discussionbridge/rich-content.js` and `rich-content.css` without
overwriting existing files. The native SSG copy configuration must carry both
selected public assets to the same `discussionbridge/` paths in the output.
The existing SSG
[copy operation](https://github.com/statamic/ssg/blob/master/src/Generator.php#L157-L170)
uses its configured map; it does not automatically copy every public file.
An operator-owned equivalent needs its own normal SSG asset configuration.

Install the package through Composer, run `php please discussionbridge:install`,
and add the presentation tag to the selected Antlers template. Before
installation, preserve the profile-specific backup set described in the
DiscussionBridge successor checkpoint.

## Browser asset maintenance

The release carries prebuilt browser scripts; site installation needs no Node
build. Contributors can reproduce both assets using `npm ci`, `npm run check`,
`npm run test:browser`, and `npm run build`. The lock retains the existing
DOMPurify 3.4.14 and esbuild 0.25.9 entries and pins Mermaid 11.17.2, the renderer
version already present in the historical prebuilt rich asset. KaTeX 0.16.47,
already in that renderer's dependency tree, is pinned for fenced `math` blocks.
The maintained wrapper uses Mermaid's strict security setting and text source;
math disables trusted commands and enforces expansion/size limits. Failed
rendering preserves the native source block. Fonts are local CSS data URLs.
This does not claim to reproduce every Discourse rich-content extension. The
build records source, lock, output and bundled-package hashes in
`resources/dist/browser-assets-provenance.json`; third-party license files are
under `resources/dist/THIRD_PARTY_LICENSES/`. No Node build is required on a site.
