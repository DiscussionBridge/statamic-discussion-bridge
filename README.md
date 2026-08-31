# DiscussionBridge for Statamic

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
- `{{ discussionbridge:record resource="{discussionbridge_resource_id}" }}`
  performs a bounded authenticated server-side pull, sanitizes the cooked first
  post, builds native page navigation, and presents the same topic's replies in
  a credential-free fullInteractive frame.
- `{{ discussionbridge:discussion }}` presents the healthy topic owned by the
  current To Discourse entry. Both tags use the same centered DiscussionBridge
  credit and responsive discussion treatment.

The addon adds two ephemeral blueprint fields to configured collection entries:
`discussionbridge_publish` for To Discourse opt-in and
`discussionbridge_resource_id` for From Discourse presentation. Field values
persist through Statamic's active repository, while delivery state remains in
the addon table.

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

Install the package through Composer, run `php artisan migrate --force`, clear
configuration and Statamic caches, and add the presentation tag to the selected
Antlers template. Before installation, preserve the profile-specific backup
set described in the DiscussionBridge successor checkpoint.
