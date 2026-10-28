<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Delivery;

use CodeWorksLabs\DiscussionBridgeStatamic\Cms\NativePreparationLimitation;
use DOMElement;
use DOMXPath;
use GuzzleHttp\Psr7\Uri;

/** Local representation limits, not a sanitizer, execution ban or remote error. */
class NativeArticleProof
{
    public function qualify(string $html): void
    {
        [$document, $root] = CapturedArticleReferences::document($html);
        foreach ((new DOMXPath($document))->query('.//*', $root) as $element) {
            $tag = strtolower($element->tagName);
            if ($tag === 'script') {
                if ($element->textContent !== '' || $element->getAttribute('src') !== '') {
                    // Both executable initialization and inert JSON/data need
                    // a concrete native/target component binding. Neither is
                    // silently removed, executed or converted to displayed code.
                    $reason = $this->dataScript($element)
                        ? 'native_article_data_binding_unavailable' : 'native_article_function_binding_unavailable';
                    throw new NativePreparationLimitation($reason);
                }
                continue;
            }
            if (in_array($tag, ['video', 'audio', 'source', 'track', 'iframe', 'object', 'embed', 'form',
                'input', 'select', 'textarea', 'button', 'svg', 'math', 'canvas', 'template', 'style', 'base'], true)) {
                throw new NativePreparationLimitation('native_article_'.$tag.'_binding_unavailable');
            }
            foreach ($element->attributes as $attribute) {
                $name = strtolower($attribute->name);
                if (in_array($name, ['onclick', 'ondblclick', 'onload', 'onerror', 'oninput', 'onchange', 'onsubmit', 'onreset',
                    'onfocus', 'onblur', 'onkeydown', 'onkeyup', 'onkeypress', 'onmousedown', 'onmouseup', 'onmouseover',
                    'onmouseout', 'onmousemove', 'onmouseenter', 'onmouseleave', 'ontouchstart', 'ontouchend',
                    'onpointerdown', 'onpointerup', 'onpointermove', 'ontoggle', 'onbeforetoggle'], true)
                    && trim($attribute->value) !== '') {
                    throw new NativePreparationLimitation('native_article_event_binding_unavailable');
                }
                if ($name === 'style' && trim($attribute->value) !== '') {
                    throw new NativePreparationLimitation('native_article_style_binding_unavailable');
                }
                if (in_array($name, ['href', 'src', 'poster', 'cite', 'data', 'action', 'formaction'], true)) {
                    $this->reference($attribute->value);
                }
                if ($name === 'srcset') {
                    foreach (explode(',', $attribute->value) as $candidate) {
                        if (! preg_match('/\A\s*(\S+)/', $candidate, $match)) {
                            throw new NativePreparationLimitation('native_srcset_representation_unavailable');
                        }
                        $this->reference($match[1]);
                    }
                }
            }
        }
    }

    private function reference(string $reference): void
    {
        if (preg_match('/[\x00-\x20\x7F]/', $reference)) {
            throw new NativePreparationLimitation('native_article_reference_representation_unavailable');
        }
        try {
            $scheme = strtolower((new Uri($reference))->getScheme());
        } catch (\InvalidArgumentException) {
            throw new NativePreparationLimitation('native_article_reference_representation_unavailable');
        }
        if (in_array($scheme, ['javascript', 'vbscript'], true)) {
            throw new NativePreparationLimitation('native_article_function_binding_unavailable');
        }
        if ($scheme === 'data') {
            throw new NativePreparationLimitation('native_article_inline_data_binding_unavailable');
        }
    }

    private function dataScript(DOMElement $script): bool
    {
        $type = strtolower(trim($script->getAttribute('type')));
        return $type !== '' && ! in_array($type, ['module', 'importmap', 'speculationrules',
            'application/ecmascript', 'application/javascript', 'application/x-ecmascript', 'application/x-javascript',
            'text/ecmascript', 'text/javascript', 'text/javascript1.0', 'text/javascript1.1', 'text/javascript1.2',
            'text/javascript1.3', 'text/javascript1.4', 'text/javascript1.5', 'text/jscript', 'text/livescript',
            'text/x-ecmascript', 'text/x-javascript'], true);
    }
}
