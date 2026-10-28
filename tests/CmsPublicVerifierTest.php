<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use Carbon\CarbonImmutable;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\CmsException;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\OwnedFragments;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\PublicVerifier;
use Illuminate\Support\Facades\Http;

class CmsPublicVerifierTest extends TestCase
{
    private function evidence(): array
    {
        $id = '75bc93ce-5f76-4cb8-a2b6-3c4bb33fb24c';
        $fragments = app(OwnedFragments::class);
        [$a, $b] = $fragments->markers('article', $id);
        [$c, $d] = $fragments->markers('discussion', $id);
        $body = str_repeat('<p>Complete native article 😀</p>', 13000);
        $expected = ['article' => $a.'<article data-native-id="native-1" data-source-revision="wiki-2">'.$body
            .'<a href="https://forum.example/t/source/9007199254740993">Original source</a></article>'.$b,
            'discussion' => $c.'<section data-presentation-mode="simple">Actual selected comments</section>'.$d];
        $mapping = (object) ['mapping_id' => $id, 'canonical_url' => 'https://statamic.example/discussionbridge/source'];
        $source = (object) ['source_revision' => 'wiki-2', 'content_sha256' => hash('sha256', $body)];
        $native = (object) ['kind' => 'publish_revision', 'native_id' => 'native-1',
            'representation' => (object) ['kind' => 'full', 'source_content_sha256' => $source->content_sha256]];
        return [$mapping, $source, $native, $expected];
    }

    public function test_anonymous_streamed_page_compares_complete_owned_fragments_above_metadata_limit_and_uses_verifier_clock(): void
    {
        [$mapping, $source, $native, $expected] = $this->evidence();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14T01:00:03.123Z'));
        $page = '<html><head><title>Native site</title></head><body>'.str_repeat('native shell ', 1000)
            .$expected['article'].'<nav>Native navigation</nav>'.$expected['discussion'].'</body></html>';
        Http::fake(['*' => Http::response($page, 200, ['Content-Type' => 'text/html; charset=UTF-8'])]);
        $proof = app(PublicVerifier::class)->verify($mapping, $source, $native, $expected,
            '2026-10-14T01:00:02.000Z', '2026-10-14T01:00:00.000Z');
        $this->assertGreaterThan(262144, strlen($expected['article']));
        $this->assertSame(hash('sha256', strlen($expected['article']).':'.$expected['article']
            .strlen($expected['discussion']).':'.$expected['discussion']), $proof->native_publication_sha256);
        $this->assertSame('2026-10-14T01:00:03.123Z', $proof->checked_at);
        $this->assertSame('statamic-owned-fragments-v1', $proof->verification_profile);
        Http::assertSent(fn ($request) => $request->url() === $mapping->canonical_url
            && ! $request->hasHeader('X-DiscussionBridge-Secret') && ! $request->hasHeader('Authorization')
            && ! $request->hasHeader('Cookie'));
    }

    public function test_same_markers_with_changed_body_duplicate_fragment_and_redirect_each_fail(): void
    {
        [$mapping, $source, $native, $expected] = $this->evidence();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14T01:00:03.123Z'));
        $sequence = Http::sequence()
            ->push(str_replace('Complete native', 'Changed native', $expected['article']).$expected['discussion'], 200, ['Content-Type' => 'text/html'])
            ->push($expected['article'].$expected['article'].$expected['discussion'], 200, ['Content-Type' => 'text/html'])
            ->push('', 302, ['Location' => $mapping->canonical_url]);
        Http::fake(['*' => $sequence]);
        for ($case = 0; $case < 3; $case++) {
            try {
                app(PublicVerifier::class)->verify($mapping, $source, $native, $expected,
                    '2026-10-14T01:00:02.000Z', '2026-10-14T01:00:00.000Z');
                $this->fail('Unverified public output accepted.');
            } catch (CmsException $error) {
                $this->assertSame('public_verification_failed', $error->failureCode);
            }
        }
    }

    public function test_withdrawal_requires_real_404_or_410_and_public_clock_after_native_sync(): void
    {
        [$mapping] = $this->evidence();
        $native = (object) ['kind' => 'withdraw', 'native_id' => 'native-1'];
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14T01:00:03.123Z'));
        Http::fake(['*' => Http::sequence()->push('<p>Error page</p>', 200, ['Content-Type' => 'text/html'])
            ->push('Gone', 410)->push('Missing', 404)]);
        try {
            app(PublicVerifier::class)->verify($mapping, null, $native, [], '2026-10-14T01:00:02.000Z', '2026-10-14T01:00:00.000Z');
            $this->fail('A 200 error page is not absence.');
        } catch (CmsException $error) {
            $this->assertSame('public_verification_failed', $error->failureCode);
        }
        $proof = app(PublicVerifier::class)->verify($mapping, null, $native, [], '2026-10-14T01:00:02.000Z', '2026-10-14T01:00:00.000Z');
        $this->assertSame(410, $proof->http_status);
        $this->assertSame('absent', $proof->kind);
        $this->expectException(CmsException::class);
        app(PublicVerifier::class)->verify($mapping, null, $native, [], '2026-10-14T01:00:04.000Z', '2026-10-14T01:00:00.000Z');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }
}
