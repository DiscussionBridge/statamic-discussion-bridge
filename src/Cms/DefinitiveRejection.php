<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

use DiscussionBridge\CmsV4\Codec;
use LogicException;
use stdClass;

/** A validated remote rejection, distinct from an uncertain or local failure. */
class DefinitiveRejection extends CmsException
{
    private function __construct(public readonly string $routeId, public readonly ?string $envelope, string $code, int $status = 422)
    {
        parent::__construct($code, $status);
    }

    public static function fromResponse(Codec $codec, string $routeId, stdClass $value, int $status): self
    {
        // The exact delivered codec owns the route/error grammar. Never accept
        // the new error using a locally weaker copy of its validation rules.
        $codec->validateResponse($routeId, $value, $status);
        $scoped = $status === 422 && ($value->code === 'content_preservation_unavailable' && in_array($routeId, ['V4-02', 'V4-29'], true)
            || $value->code === 'invalid_field' && $routeId === 'V4-02')
            || $status === 409 && $routeId === 'V4-09' && $value->code === 'revision_conflict';
        if (! $scoped
            || $value->retryable !== false || $value->retry_after_seconds !== null) {
            throw new LogicException('Not a scoped definitive rejection.');
        }
        return new self($routeId, $codec->encodeJson($value), $value->code, $status);
    }

    /** Only after ReplayJournal checks a protected exact route/refusal witness. No Error is fabricated. */
    public static function retainedInvalidField(): self { return new self('V4-02', null, 'invalid_field'); }
}
