<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

use Carbon\CarbonImmutable;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use Illuminate\Support\Facades\Http;
use stdClass;

/** Anonymous canonical fetch; compare owned bytes, never a source marker alone. */
class PublicVerifier
{
    public function __construct(private readonly Configuration $configuration, private readonly OwnedFragments $fragments)
    {
    }

    public function verify(stdClass $mapping, ?stdClass $source, stdClass $nativeResult, array $expected,
        string $notBefore, string $serverTime): stdClass
    {
        return $this->fetch($mapping, $source, $nativeResult, $expected, $notBefore, $serverTime);
    }

    /** Fresh anonymous read; context creation is not a shared clock or lease bound. */
    public function inspect(stdClass $mapping, ?stdClass $source, stdClass $nativeResult, array $expected,
        string $notBefore): stdClass
    {
        return $this->fetch($mapping, $source, $nativeResult, $expected, null, null);
    }

    private function fetch(stdClass $mapping, ?stdClass $source, stdClass $nativeResult, array $expected,
        ?string $notBefore, ?string $serverTime): stdClass
    {
        $url = $this->configuration->canonicalPageUrl($mapping->canonical_url);
        $budget = array_sum(array_map('strlen', $expected)) + 2 * 1024 * 1024;
        // The bound is surrounding native page chrome plus the expected full
        // fragments, not a universal From content or metadata-response ceiling.
        $response = Http::withHeaders(['Accept' => 'text/html', 'Accept-Encoding' => 'identity'])
            ->connectTimeout((int) config('discussionbridge.connect_timeout_seconds', 2))
            ->timeout((int) config('discussionbridge.response_timeout_seconds', 5))
            ->withOptions(['allow_redirects' => false, 'stream' => true, 'decode_content' => false, 'cookies' => false])
            ->get($url);
        $stream = $response->toPsrResponse()->getBody();
        $spool = fopen('php://temp/maxmemory:65536', 'w+b');
        if ($spool === false) {
            $stream->close();
            throw new CmsException('storage_exhausted');
        }
        try {
            $encoding = strtolower(trim($response->header('Content-Encoding')));
            if ($encoding !== '' && $encoding !== 'identity') {
                throw new CmsException('public_verification_failed');
            }
            if ($nativeResult->kind === 'withdraw') {
                if (! in_array($response->status(), [404, 410], true)) {
                    throw new CmsException('public_verification_failed');
                }
            } elseif ($response->status() !== 200 || $expected === []
                || preg_match('/\Atext\/html(?:\s*;\s*charset\s*=\s*(?:UTF-8|"UTF-8"))?\s*\z/i', $response->header('Content-Type')) !== 1) {
                throw new CmsException('public_verification_failed');
            }
            $read = 0;
            while (! $stream->eof()) {
                $bytes = $stream->read(min(32768, $budget + 1 - $read));
                if ($bytes === '' && ! $stream->eof()) {
                    throw new CmsException('public_verification_failed');
                }
                $read += strlen($bytes);
                if ($read > $budget || fwrite($spool, $bytes) !== strlen($bytes)) {
                    throw new CmsException('public_verification_failed');
                }
            }
            if ($nativeResult->kind !== 'withdraw') {
                $this->compareFragments($spool, $mapping->mapping_id, $expected);
            }
            $checked = CarbonImmutable::now('UTC');
            if ($notBefore !== null && $checked->lessThan(CarbonImmutable::parse($notBefore))
                || $serverTime !== null && $checked->greaterThan(CarbonImmutable::parse($serverTime)->addSeconds(300))) {
                throw new CmsException('public_verification_failed');
            }
            $instant = $checked->format('Y-m-d\TH:i:s.v\Z');
            if ($nativeResult->kind === 'withdraw') {
                return (object) ['kind' => 'absent', 'url' => $url, 'native_id' => $nativeResult->native_id,
                    'checked_at' => $instant, 'http_status' => $response->status()];
            }
            return (object) ['kind' => 'published', 'url' => $url, 'native_id' => $nativeResult->native_id,
                'source_revision' => $source->source_revision, 'source_content_sha256' => $source->content_sha256,
                'native_publication_sha256' => $this->fragments->sha256($expected),
                'verification_profile' => OwnedFragments::PROFILE, 'checked_at' => $instant,
                'representation' => $nativeResult->representation];
        } finally {
            $stream->close();
            fclose($spool);
        }
    }

    /** Incremental comparison; keep only the marker suffix and one read block. */
    public function compareFragments($stream, string $mappingId, array $expected): void
    {
        foreach ($expected as $kind => $fragment) {
            $this->compare($stream, $mappingId, $kind, $fragment);
        }
    }

    /** Incremental comparison; keep only the marker suffix and one read block. */
    private function compare($spool, string $mappingId, string $kind, string $expected): void
    {
        [$begin, $end] = $this->fragments->markers($kind, $mappingId);
        rewind($spool);
        $buffer = '';
        $inside = false;
        $occurrences = 0;
        $offset = 0;
        while (! feof($spool) || $buffer !== '') {
            if (! feof($spool)) {
                $buffer .= fread($spool, 8192);
            }
            if (! $inside) {
                $position = strpos($buffer, $begin);
                if ($position === false) {
                    $buffer = feof($spool) ? '' : substr($buffer, -(strlen($begin) - 1));
                    continue;
                }
                $occurrences++;
                if ($occurrences !== 1) {
                    throw new CmsException('public_verification_failed');
                }
                $buffer = substr($buffer, $position);
                $inside = true;
            }
            $position = strpos($buffer, $end);
            $length = $position === false ? max(0, strlen($buffer) - strlen($end) + 1) : $position + strlen($end);
            if ($length === 0 && feof($spool)) {
                throw new CmsException('public_verification_failed');
            }
            if ($offset + $length > strlen($expected) || substr($expected, $offset, $length) !== substr($buffer, 0, $length)) {
                throw new CmsException('public_verification_failed');
            }
            $offset += $length;
            $buffer = substr($buffer, $length);
            if ($position !== false) {
                if ($offset !== strlen($expected)) {
                    throw new CmsException('public_verification_failed');
                }
                $inside = false;
            }
        }
        if ($inside || $occurrences !== 1 || $offset !== strlen($expected)) {
            throw new CmsException('public_verification_failed');
        }
    }
}
