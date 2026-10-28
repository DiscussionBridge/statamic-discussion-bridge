<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

use Carbon\CarbonImmutable;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use Illuminate\Support\Facades\Http;
use stdClass;

/** Actual anonymous full source read; never a From receipt or a nonce-only check. */
class ToSourcePublicVerifier
{
    public function __construct(private readonly Configuration $configuration, private readonly ToSourceArticleProfile $profile) {}

    public function verify(array $projection, string $notBefore): stdClass
    {
        $url = $this->configuration->canonicalPageUrl($projection['snapshot']['canonical_url']);
        $budget = strlen($this->profile->expectedFrame($projection)) + 2 * 1024 * 1024;
        $response = Http::replaceHeaders(['Accept' => 'text/html', 'Accept-Encoding' => 'identity'])
            ->connectTimeout((int) config('discussionbridge.connect_timeout_seconds', 2))
            ->timeout((int) config('discussionbridge.response_timeout_seconds', 5))
            ->withOptions(['allow_redirects' => false, 'stream' => true, 'decode_content' => false, 'cookies' => false, 'auth' => null])
            ->beforeSending(function ($request) {
                foreach (['Authorization', 'Cookie', 'X-DiscussionBridge-Connection', 'X-DiscussionBridge-Secret'] as $header) {
                    if ($request->hasHeader($header)) {
                        throw new CmsException('scope_denied');
                    }
                }
            })
            ->get($url);
        $stream = $response->toPsrResponse()->getBody();
        $spool = fopen('php://temp/maxmemory:65536', 'w+b');
        if ($spool === false) {
            $stream->close();
            throw new CmsException('storage_exhausted');
        }
        try {
            if ($response->status() !== 200
                || ! in_array(strtolower(trim($response->header('Content-Encoding'))), ['', 'identity'], true)
                || preg_match('/\Atext\/html(?:\s*;\s*charset\s*=\s*(?:UTF-8|"UTF-8"))?\s*\z/i', $response->header('Content-Type')) !== 1) {
                throw new CmsException('public_verification_failed');
            }
            $read = 0;
            while (! $stream->eof()) {
                $bytes = $stream->read(min(32768, $budget + 1 - $read));
                $read += strlen($bytes);
                if ($bytes === '' && ! $stream->eof() || $read > $budget || fwrite($spool, $bytes) !== strlen($bytes)) {
                    throw new CmsException('public_verification_failed');
                }
            }
            $sha = $this->profile->compare($spool, $projection);
            $checked = CarbonImmutable::now('UTC');
            if ($checked->lessThan(CarbonImmutable::parse($notBefore))) {
                throw new CmsException('public_verification_failed');
            }
            return (object) ['url' => $url, 'external_id' => $projection['snapshot']['external_id'],
                'source_revision' => $projection['source_revision'],
                'source_content_sha256' => $projection['source']['record']['source_publication']['content_sha256'],
                'native_ownership_receipt_sha256' => $projection['receipt_sha256'],
                'native_publication_sha256' => $sha, 'verification_profile' => ToSourceArticleProfile::PROFILE,
                'checked_at' => $checked->format('Y-m-d\TH:i:s.v\Z')];
        } finally {
            $stream->close();
            fclose($spool);
        }
    }

    /** Private truthful prior-artifact check, not a29 proof or native URL undo. */
    public function verifyRestored(string $url, string $expected, string $notBefore): array
    {
        $url = $this->configuration->canonicalPageUrl($url);
        if ($expected === '') {
            throw new CmsException('native_restore_required');
        }
        $response = Http::replaceHeaders(['Accept' => 'text/html', 'Accept-Encoding' => 'identity'])
            ->connectTimeout((int) config('discussionbridge.connect_timeout_seconds', 2))
            ->timeout((int) config('discussionbridge.response_timeout_seconds', 5))
            ->withOptions(['allow_redirects' => false, 'stream' => true, 'decode_content' => false, 'cookies' => false, 'auth' => null])
            ->beforeSending(function ($request) {
                foreach (['Authorization', 'Cookie', 'X-DiscussionBridge-Connection', 'X-DiscussionBridge-Secret'] as $header) {
                    if ($request->hasHeader($header)) {
                        throw new CmsException('scope_denied');
                    }
                }
            })->get($url);
        $stream = $response->toPsrResponse()->getBody();
        try {
            if ($response->status() !== 200
                || ! in_array(strtolower(trim($response->header('Content-Encoding'))), ['', 'identity'], true)
                || preg_match('/\Atext\/html(?:\s*;\s*charset\s*=\s*(?:UTF-8|"UTF-8"))?\s*\z/i', $response->header('Content-Type')) !== 1) {
                throw new CmsException('native_restore_required');
            }
            $budget = strlen($expected) + 2 * 1024 * 1024;
            $read = $count = 0;
            $buffer = '';
            $observed = null;
            while (! $stream->eof()) {
                $bytes = $stream->read(min(32768, $budget + 1 - $read));
                $read += strlen($bytes);
                if ($read > $budget || $bytes === '' && ! $stream->eof()) {
                    throw new CmsException('native_restore_required');
                }
                $buffer .= $bytes;
                while (($position = strpos($buffer, $expected)) !== false) {
                    $observed = hash('sha256', substr($buffer, $position, strlen($expected)));
                    $count++;
                    $buffer = substr($buffer, $position + strlen($expected));
                }
                $buffer = substr($buffer, -max(1, strlen($expected) - 1));
            }
            $checked = CarbonImmutable::now('UTC');
            if ($count !== 1 || $checked->lessThan(CarbonImmutable::parse($notBefore))) {
                throw new CmsException('native_restore_required');
            }
            return ['url' => $url, 'article_sha256' => $observed,
                'checked_at' => $checked->format('Y-m-d\TH:i:s.v\Z'), 'http_status' => 200];
        } finally {
            $stream->close();
        }
    }
}
