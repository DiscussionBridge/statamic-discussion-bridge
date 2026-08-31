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
.discussionbridge-article{max-width:48rem;margin-inline:auto;font-size:1.0625rem;line-height:1.75}.discussionbridge-article h2{margin:2.5rem 0 .75rem;font-size:1.75rem;font-weight:750;line-height:1.25;letter-spacing:-.02em}.discussionbridge-article h3{margin:2rem 0 .6rem;font-size:1.3rem;font-weight:700;line-height:1.35}.discussionbridge-article p,.discussionbridge-article ul,.discussionbridge-article ol,.discussionbridge-article table,.discussionbridge-article pre,.discussionbridge-article figure{margin:1rem 0}.discussionbridge-article img{display:block;max-width:100%;height:auto;margin:1.5rem auto;border-radius:.75rem}.discussionbridge-article table{width:100%;border-collapse:collapse}.discussionbridge-article th,.discussionbridge-article td{padding:.65rem .75rem;border:1px solid color-mix(in srgb,currentColor 18%,transparent);text-align:left}.discussionbridge-article pre{overflow:auto;padding:1rem;border-radius:.5rem;background:color-mix(in srgb,currentColor 8%,transparent)}.discussionbridge-article code{font-size:.9em}.discussionbridge-toc{margin:0 0 2.5rem;padding:1rem 1.25rem;border:1px solid color-mix(in srgb,currentColor 22%,transparent);border-radius:.5rem}.discussionbridge-toc strong{display:block;margin-bottom:.5rem}.discussionbridge-toc ol{margin:0;padding-left:1.25rem}.discussionbridge-toc__level-h3{margin-left:1.25rem}.discussionbridge-discussion{box-sizing:border-box;width:100%;max-width:48rem;margin:3rem auto 0;padding-top:2rem;border-top:1px solid color-mix(in srgb,currentColor 22%,transparent)}.discussionbridge-discussion__header{display:flex;align-items:baseline;justify-content:space-between;gap:1rem;margin-bottom:1rem}.discussionbridge-discussion__header h2{margin:0;font-size:1.5rem;font-weight:750}.discussionbridge-discussion iframe{display:block;box-sizing:border-box;width:100%;max-width:100%;min-height:360px;height:800px!important;border:0}.discussionbridge-credit{margin:.45rem 0 0;text-align:center;font-size:.875rem;font-weight:600;line-height:1.4;opacity:.72}.discussionbridge-credit__brand{position:relative;color:inherit;text-decoration:none;transition:color 160ms ease}.discussionbridge-credit__brand::after{position:absolute;right:0;bottom:-.12em;left:0;height:1px;background:currentColor;content:"";transform:scaleX(0);transform-origin:center;transition:transform 180ms ease}.discussionbridge-credit__brand:hover,.discussionbridge-credit__brand:focus-visible{color:#3451b2;opacity:1}.discussionbridge-credit__brand:hover::after,.discussionbridge-credit__brand:focus-visible::after{transform:scaleX(1)}.discussionbridge-credit__brand:focus-visible{border-radius:.15rem;outline:2px solid currentColor;outline-offset:.2rem}@media(max-width:520px){.discussionbridge-article{font-size:1rem}.discussionbridge-article h2{font-size:1.45rem}.discussionbridge-discussion{margin-top:2rem;padding-top:1.5rem}.discussionbridge-discussion__header{align-items:flex-start;flex-direction:column}.discussionbridge-discussion iframe{height:min(800px,75vh)!important}}@media(prefers-reduced-motion:reduce){.discussionbridge-credit__brand,.discussionbridge-credit__brand::after{transition:none}}
</style>
HTML;
    }
}
