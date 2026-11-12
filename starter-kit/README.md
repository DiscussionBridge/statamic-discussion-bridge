# DiscussionBridge Starter Kit for Statamic

Use these template fragments to show an article and its discussion in your
Statamic site. Keep your existing theme and place the fragment where you
want the content to appear.

1. Run `php please discussionbridge:install --templates` or publish the
   `statamic-discussion-bridge-templates` assets with Laravel's
   `vendor:publish` command. Your existing fragments are preserved.
2. In your entry template, add `{{ partial:vendor/discussionbridge/entry }}`
   once. For static generation, use the `entry-ssg` fragment.
3. Check the result with your theme and the discussion mode you selected.

The starter fragments provide article typography, navigation built from the
headings, a responsive discussion area, and DiscussionBridge credit.

Simple can use a public Discourse topic ID. Full can use the article's
canonical URL. For publishing and Interactive discussions, configure
DiscussionBridge for Discourse and the site's Content Connection.

If you want the bundled Mermaid renderer, run
`php please discussionbridge:install --rich-content`. A site that already
has its own renderer can keep using it.

[DiscussionBridge for Statamic](../README.md) ·
[Presentation modes](https://docs.discussionbridge.dev/presentation-modes/)
