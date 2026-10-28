<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Delivery;

use CodeWorksLabs\DiscussionBridgeStatamic\Cms\NativePreparationLimitation;
use Statamic\Contracts\View\Antlers\Parser;
use Statamic\Facades\Site;
use Statamic\Fields\Value;
use Statamic\Fieldtypes\Bard;
use Statamic\Fieldtypes\Markdown;

/** Render the actual selected field, never a guessed default parser or set partial. */
class NativeContentRenderer
{
    public function render($entry, mixed $original): array
    {
        $field = $entry->blueprint()->field('content');
        if (! $field) {
            throw new NativePreparationLimitation('native_content_field_unbound');
        }
        if (Site::current()->handle() !== $entry->site()->handle()) {
            throw new NativePreparationLimitation('native_article_site_context_unbound');
        }
        // This public native path binds the captured value and the real Entry
        // parent. Field::augment returns a Field containing a native Value.
        $value = $field->newInstance()->setParent($entry)->setValue($original)->augment()->value();
        if (! $value instanceof Value) {
            throw new NativePreparationLimitation('native_content_field_not_supported');
        }
        $type = $value->fieldtype();
        if (! $type instanceof Markdown && ! $type instanceof Bard) {
            throw new NativePreparationLimitation('native_content_field_not_supported');
        }
        if ($type instanceof Markdown && ! is_string($original)) {
            throw new NativePreparationLimitation('native_content_field_not_supported');
        }
        $html = $value->shouldParseAntlers()
            ? $value->antlersValue(app(Parser::class), $entry->toAugmentedArray())
            : $value->value();
        if ($html instanceof \Statamic\View\Antlers\AntlersString) {
            $html = (string) $html;
        }
        if (! is_string($html)) {
            // Native Bard sets are ordered augmented Values, not HTML. No
            // actual site set/article template binding is supplied here.
            throw new NativePreparationLimitation('native_ordered_set_presentation_unbound');
        }

        return ['html' => $html, 'field_type' => get_class($type)];
    }
}
