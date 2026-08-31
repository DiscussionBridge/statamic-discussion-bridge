# DiscussionBridge Starter Kit for Statamic

This is an additive integration layer for an existing Statamic site, not a
replacement theme. It provides the recommended template placement, native
article typography, heading-derived page navigation, responsive discussion
frame and DiscussionBridge credit.

Merge the supplied template fragment into the site's entry template. Keep the
site's own header, footer, design tokens and content layout. The addon owns the
connection, delivery, presentation and discussion behavior; a site theme owns
the surrounding brand experience.

The later DiscussionBridge showcase theme is separate and optional.

The supplied template also shows the four initial presentation boundaries:
plugin-free Simple, plugin-free Full, publishing to The Bridge with
fullInteractive discussion, and From The Bridge. Use the site's canonical URL
for Full mode and an ordinary public Discourse topic ID for Simple mode.
Simple and Full both render their entry body through the addon's native article
typography and heading-derived page navigation. Full leaves iframe height under
Discourse Core's measured embed contract rather than imposing a fixed canvas.
