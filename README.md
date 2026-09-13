# DiscussionBridge for Statamic

```sh
git clone https://github.com/DiscussionBridge/statamic-discussion-bridge.git
```

This Statamic 6 addon connects an explicitly opted-in, durably published entry
to the DiscussionBridge Bridge Record contract. The same package serves the
Alpha Statamic Flat and Statamic DB profiles; each installation has its own
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
  destination or resource collisions fail closed.
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

Set these nonsecret values in the application's protected environment:

```dotenv
DISCUSSIONBRIDGE_ENABLED=true
DISCUSSIONBRIDGE_FORUM_URL=https://sandbox-forum.discussionbridge.dev
DISCUSSIONBRIDGE_SITE_ORIGIN=https://statamic-flat-sandbox.codeworkslabs.net
DISCUSSIONBRIDGE_CONNECTION_ID=dbc_000000000000000000000000
DISCUSSIONBRIDGE_SECRET_FILE=/etc/discussionbridge-statamic-flat/connection-secret
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

Do not deploy output when the preparation command fails. A generated site may
be hosted without PHP, Statamic, a connection secret or an adapter worker; only
the protected authoring/build application performs receiver-authenticated
requests.

Install the package through Composer, run `php artisan migrate --force`, clear
configuration and Statamic caches, and add the presentation tag to the selected
Antlers template. Before installation, preserve the profile-specific backup
set described in the DiscussionBridge successor checkpoint.
