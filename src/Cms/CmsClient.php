<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use DiscussionBridge\CmsV4\Codec;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use stdClass;

/** Single V4 typed CMS transport using the exact private pair. */
class CmsClient
{
    private Codec $codec;
    private ?int $responseStatus = null;

    public function __construct(private readonly Configuration $configuration)
    {
        require_once __DIR__.'/Shared/CmsCodec.php';
        $this->codec = new Codec(__DIR__.'/Shared/cms-schema.json');
    }

    public function codec(): Codec
    {
        return $this->codec;
    }

    public function responseStatus(): ?int
    {
        return $this->responseStatus;
    }

    public function request(string $id, array $path = [], ?stdClass $query = null, ?stdClass $body = null): mixed
    {
        if (! $this->configuration->enabled()) {
            throw new CmsException('connection_disabled');
        }
        $route = $this->codec->route($id);
        $options = ['path' => $path, 'method' => $route['method'], 'query' => $query ?? new stdClass];
        if ($body !== null) {
            $options['body'] = $body;
        }
        $this->codec->validateRoute($id, $options);
        $urlPath = $route['path'];
        foreach ($path as $key => $value) {
            $urlPath = str_replace('{'.$key.'}', rawurlencode($value), $urlPath);
        }
        $url = $this->configuration->forumOrigin().$urlPath;
        if ($query !== null && get_object_vars($query) !== []) {
            $url .= '?'.http_build_query(get_object_vars($query), '', '&', PHP_QUERY_RFC3986);
        }
        $rawBody = $body === null ? '' : $this->codec->encodeJson($body);
        if (strlen($rawBody) > 65536) {
            throw new CmsException('body_too_large');
        }
        $responseTimeout = (int) config('discussionbridge.response_timeout_seconds', 5);
        try {
            $request = Http::withHeaders([
                'Accept' => 'application/json', 'Accept-Encoding' => 'identity',
                'X-DiscussionBridge-Connection' => $this->configuration->connectionId(),
                'X-DiscussionBridge-Secret' => $this->configuration->secret(),
            ])->connectTimeout((int) config('discussionbridge.connect_timeout_seconds', 2))
                ->timeout($responseTimeout)
                ->withOptions(['allow_redirects' => false, 'stream' => true, 'decode_content' => false]);
            if ($body !== null) {
                $request = $request->withBody($rawBody, 'application/json');
            }
            $response = $request->send($route['method'], $url);
        } catch (ConnectionException) {
            throw new CmsException('transport_timeout');
        }
        $maximum = $route['response'] === 'ContentChunk' ? 65536 : 262144;
        $stream = $response->toPsrResponse()->getBody();
        try {
            if ($response->status() >= 300 && $response->status() < 400) {
                throw new CmsException('scope_denied', $response->status());
            }
            $encoding = strtolower(trim($response->header('Content-Encoding')));
            if ($encoding !== '' && $encoding !== 'identity') {
                throw new CmsException('unsupported_media_type');
            }
            if ($response->status() !== 204
                && preg_match('/\Aapplication\/json(?:\s*;\s*charset\s*=\s*(?:UTF-8|"UTF-8"))?\s*\z/i', $response->header('Content-Type')) !== 1) {
                throw new CmsException('unsupported_media_type');
            }
            $raw = '';
            $noProgressStarted = null;
            $noProgressAllowance = max(0, $responseTimeout) * 1_000_000_000;
            $backoffMicroseconds = 1000;
            while (true) {
                if ($noProgressStarted !== null && hrtime(true) - $noProgressStarted >= $noProgressAllowance) {
                    throw new CmsException('transport_timeout');
                }
                $atEnd = $stream->eof();
                if ($stream->getMetadata('timed_out') === true
                    || $noProgressStarted !== null && hrtime(true) - $noProgressStarted >= $noProgressAllowance) {
                    throw new CmsException('transport_timeout');
                }
                if ($atEnd) {
                    break;
                }
                $piece = $stream->read(min(8192, $maximum + 1 - strlen($raw)));
                if ($stream->getMetadata('timed_out') === true) {
                    throw new CmsException('transport_timeout');
                }
                // A blocking read can overrun this no-progress allowance by its
                // existing socket timeout. Never accept late progress as a reset.
                if ($noProgressStarted !== null && hrtime(true) - $noProgressStarted >= $noProgressAllowance) {
                    throw new CmsException('transport_timeout');
                }
                if ($piece === '') {
                    $atEnd = $stream->eof();
                    if ($stream->getMetadata('timed_out') === true
                        || $noProgressStarted !== null && hrtime(true) - $noProgressStarted >= $noProgressAllowance) {
                        throw new CmsException('transport_timeout');
                    }
                    if ($atEnd) {
                        break;
                    }
                    $noProgressStarted ??= hrtime(true);
                    $remaining = $noProgressAllowance - (hrtime(true) - $noProgressStarted);
                    if ($remaining <= 0) {
                        throw new CmsException('transport_timeout');
                    }
                    // No HTTP retry or whole-body deadline: yield only while
                    // waiting for this stream to make progress, under its config.
                    usleep(max(1, min($backoffMicroseconds, intdiv($remaining, 1000))));
                    $backoffMicroseconds = min(10000, $backoffMicroseconds * 2);
                    continue;
                }
                $raw .= $piece;
                if (strlen($raw) > $maximum) {
                    throw new CmsException('metadata_unavailable');
                }
                $noProgressStarted = null;
                $backoffMicroseconds = 1000;
            }
        } finally {
            $stream->close();
        }
        $value = $this->codec->decodeResponse($id, $raw, $response->status());
        $this->responseStatus = $response->status();
        if ($id === 'V4-02' && $response->status() === 409 && $value->outcome === 'reconciliation_required') {
            return $value;
        }
        if (! in_array($response->status(), $route['success_http'], true)) {
            if ($value->code === 'content_preservation_unavailable' && in_array($id, ['V4-02', 'V4-29'], true)
                || $value->code === 'invalid_field' && $id === 'V4-02' && $response->status() === 422) {
                throw DefinitiveRejection::fromResponse($this->codec, $id, $value, $response->status());
            }
            throw new CmsException($value->code, $response->status());
        }
        return $value;
    }
}
