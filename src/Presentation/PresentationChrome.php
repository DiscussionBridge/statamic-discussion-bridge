<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Presentation;

class PresentationChrome
{
    private static bool $stylesRendered = false;

    public function discussion(int $topicId, string $topicUrl, string $forumOrigin, bool $sourcePresentation): string
    {
        $configuration = [
            'discourseUrl' => rtrim($forumOrigin, '/').'/',
            'topicId' => $topicId,
            'fullApp' => true,
            'dynamicHeight' => false,
            ...($sourcePresentation ? ['className' => 'discussion-bridge-source-presentation'] : []),
        ];

        return $this->styles()
            .'<section class="discussionbridge-discussion">'
            .'<div class="discussionbridge-discussion__header"><h2>Discussion</h2><a href="'.htmlspecialchars($topicUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" rel="nofollow noopener noreferrer">Open in Discourse</a></div>'
            .'<div id="discourse-comments"></div>'
            .$this->credit()
            .'</section>'
            .'<script>window.DiscourseEmbed='.json_encode($configuration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).';</script>'
            .'<script async src="'.htmlspecialchars(rtrim($forumOrigin, '/').'/javascripts/embed.js', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"></script>';
    }

    public function credit(): string
    {
        return '<footer class="discussionbridge-credit" aria-label="DiscussionBridge credit"><span class="discussionbridge-credit__prefix">Connected by</span> <a class="discussionbridge-credit__brand" href="https://discussionbridge.dev/" rel="nofollow">DiscussionBridge</a></footer>';
    }

    public function styles(): string
    {
        if (self::$stylesRendered) {
            return '';
        }
        self::$stylesRendered = true;

        return <<<'HTML'
<style data-discussionbridge-presentation>
.discussionbridge-toc{margin:2rem 0;padding:1rem 1.25rem;border:1px solid color-mix(in srgb,currentColor 22%,transparent);border-radius:.5rem}.discussionbridge-toc strong{display:block;margin-bottom:.5rem}.discussionbridge-toc ol{margin:0;padding-left:1.25rem}.discussionbridge-toc__level-h3{margin-left:1.25rem}.discussionbridge-discussion{margin-top:3rem;padding-top:2rem;border-top:1px solid color-mix(in srgb,currentColor 22%,transparent)}.discussionbridge-discussion__header{display:flex;align-items:baseline;justify-content:space-between;gap:1rem;margin-bottom:1rem}.discussionbridge-discussion__header h2{margin:0}.discussionbridge-discussion iframe{display:block;width:100%;min-height:360px;height:800px!important;border:0}.discussionbridge-credit{margin:.45rem 0 0;text-align:center;font-size:.875rem;font-weight:600;line-height:1.4;opacity:.72}.discussionbridge-credit__brand{position:relative;color:inherit;text-decoration:none;transition:color 160ms ease}.discussionbridge-credit__brand::after{position:absolute;right:0;bottom:-.12em;left:0;height:1px;background:currentColor;content:"";transform:scaleX(0);transform-origin:center;transition:transform 180ms ease}.discussionbridge-credit__brand:hover,.discussionbridge-credit__brand:focus-visible{color:#3451b2;opacity:1}.discussionbridge-credit__brand:hover::after,.discussionbridge-credit__brand:focus-visible::after{transform:scaleX(1)}.discussionbridge-credit__brand:focus-visible{border-radius:.15rem;outline:2px solid currentColor;outline-offset:.2rem}@media(max-width:520px){.discussionbridge-discussion__header{align-items:flex-start;flex-direction:column}.discussionbridge-discussion iframe{height:min(800px,75vh)!important}}@media(prefers-reduced-motion:reduce){.discussionbridge-credit__brand,.discussionbridge-credit__brand::after{transition:none}}
</style>
HTML;
    }
}
