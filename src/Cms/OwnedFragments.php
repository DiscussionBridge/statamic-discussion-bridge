<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\HtmlSanitizer;
use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\PageNavigation;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\PresentationAssets;

/** Versioned native proof over actual article and selected discussion output bytes. */
class OwnedFragments
{
    public const PROFILE = 'statamic-owned-fragments-v1';

    public function article($entry): string
    {
        $source = $entry->get('content');
        if (! is_string($source)) {
            throw new CmsException('native_ownership_conflict');
        }
        $content = app(HtmlSanitizer::class)->sanitize($source, null);
        $html = '<article class="discussionbridge-article">'.app(PageNavigation::class)->render($content).'</article>'
            .PresentationAssets::nativeMarkup($source);
        return $this->frame('article', $entry, $html);
    }

    public function discussion($entry): string
    {
        $html = $entry->get('discussionbridge_cms_discussion_html');
        if (! is_string($html) || $html === '') {
            throw new CmsException('presentation_unsupported');
        }
        return $this->frame('discussion', $entry, $html);
    }

    public function expected($entry): array
    {
        return ['article' => $this->article($entry), 'discussion' => $this->discussion($entry)];
    }

    public function sha256(array $fragments): string
    {
        return hash('sha256', strlen($fragments['article']).':'.$fragments['article']
            .strlen($fragments['discussion']).':'.$fragments['discussion']);
    }

    public function markers(string $kind, string $mappingId): array
    {
        if (! in_array($kind, ['article', 'discussion'], true)
            || preg_match('/\A[0-9a-f-]{36}\z/', $mappingId) !== 1) {
            throw new CmsException('native_ownership_conflict');
        }
        $prefix = '<!-- discussionbridge:cms:'.$kind.':'.$mappingId.':';
        return [$prefix.'begin -->', $prefix.'end -->'];
    }

    private function frame(string $kind, $entry, string $html): string
    {
        [$begin, $end] = $this->markers($kind, $entry->get('discussionbridge_cms_mapping_id'));
        $attributes = ['data-native-id' => (string) $entry->id(), 'data-source-revision' => $entry->get('discussionbridge_source_revision'),
            'data-presentation-mode' => $entry->get('discussionbridge_mode'),
            'data-ownership-receipt' => $entry->get('discussionbridge_cms_receipt_sha256')];
        $output = '';
        foreach ($attributes as $name => $value) {
            if (! is_string($value)) {
                throw new CmsException('native_ownership_conflict');
            }
            $output .= ' '.$name.'="'.htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
        }
        return $begin.'<div'.$output.'>'.$html.'</div>'.$end;
    }
}
