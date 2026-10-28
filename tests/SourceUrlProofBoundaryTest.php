<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Cms\CmsException;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\PrivateState;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\ToSourceArticleProfile;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\ToSourcePublicVerifier;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\ToSourceSnapshot;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Actual expectedFrame/compare/verifier and Laravel HTTP fake, no Testbench/DB/native service. */
class SourceUrlProofBoundaryTest extends TestCase
{
    private Container $container;
    private Factory $http;
    private array $projection;
    private ControlledSourceProofProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container = new Container;
        Container::setInstance($this->container);
        $this->container->instance('config', new Repository(['discussionbridge' => ['site_origin' => 'https://statamic.example']]));
        Facade::setFacadeApplication($this->container);
        $this->http = new Factory;
        $this->http->preventStrayRequests();
        Http::swap($this->http);
        $this->projection = ['snapshot' => ['canonical_url' => 'https://statamic.example/moved', 'external_id' => 'native-entry'],
            'row' => [], 'source_revision' => 'moved-native-edition', 'move_id' => 'issued-move', 'proof_nonce' => 'issued-nonce',
            'receipt_sha256' => str_repeat('a', 64), 'source' => ['record' => ['source_publication' => ['content_sha256' => str_repeat('b', 64)]]]];
        $snapshots = new class($this->projection['snapshot']) extends ToSourceSnapshot {
            public function __construct(private array $fixture) {}
            public function read(object $row, ?string $path = null, bool $render = true): array { return ['snapshot' => $this->fixture, 'entry' => (object) []]; }
        };
        $store = (new \ReflectionClass(PrivateState::class))->newInstanceWithoutConstructor();
        $this->profile = new ControlledSourceProofProfile($store, $snapshots);
        [$begin, $end] = $this->profile->markers($this->projection);
        $this->profile->fixtureFrame = $begin.'<article data-discussionbridge-source-profile="'.ToSourceArticleProfile::PROFILE.'"><p>Complete é source.</p><template>Legitimate source example</template><span hidden>Source-owned detail</span></article>'.$end;
        $this->profile->fixturePage = '<html><body><main>'.$this->profile->fixtureFrame.'</main></body></html>';
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    public static function inactiveContexts(): array
    {
        return [
            'template' => ['<template>', '</template>'], 'hidden' => ['<div hidden>', '</div>'],
            'hidden-until-found' => ['<div hidden="until-found">', '</div>'], 'inert' => ['<div inert>', '</div>'],
            'display-none' => ['<div style="DISPLAY : none !important">', '</div>'],
            'visibility-hidden' => ['<div style="visibility:hidden">', '</div>'],
            'content-visibility-hidden' => ['<div style="content-visibility:hidden">', '</div>'],
            'script-data' => ['<script type="application/json">', '</script>'], 'textarea' => ['<textarea>', '</textarea>'],
            'noscript' => ['<noscript>', '</noscript>'],
        ];
    }

    #[DataProvider('inactiveContexts')]
    public function test_actual_verifier_rejects_inactive_public_context_without_changing_projection(string $before, string $after): void
    {
        $expected = $this->projection;
        $this->http->fake(fn () => $this->http->response('<html><body>'.$before.$this->profile->fixtureFrame.$after.'</body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']));
        try {
            (new ToSourcePublicVerifier(new Configuration, $this->profile))->verify($this->projection, '2020-01-01T00:00:00.000Z');
            $this->fail('Inactive public source qualified.');
        } catch (CmsException $error) {
            $this->assertSame('public_verification_failed', $error->failureCode);
        } finally {
            $this->assertSame($expected, $this->projection);
        }
    }

    #[DataProvider('inactiveContexts')]
    public function test_actual_native_expected_frame_rejects_inactive_context(string $before, string $after): void
    {
        $this->profile->fixturePage = '<html><body>'.$before.$this->profile->fixtureFrame.$after.'</body></html>';
        $this->expectException(CmsException::class);
        $this->profile->expectedFrame($this->projection);
    }

    public function test_actual_verifier_accepts_complete_visible_article_and_legitimate_inactive_descendants(): void
    {
        $this->http->fake(fn ($request) => $this->http->response($this->profile->fixturePage, 200, ['Content-Type' => 'text/html; charset=UTF-8']));
        $proof = (new ToSourcePublicVerifier(new Configuration, $this->profile))->verify($this->projection, '2020-01-01T00:00:00.000Z');
        $this->assertSame(hash('sha256', $this->profile->fixtureFrame), $proof->native_publication_sha256);
        $this->assertSame($this->projection['receipt_sha256'], $proof->native_ownership_receipt_sha256);
        $this->assertSame($this->projection['source_revision'], $proof->source_revision);
        $this->http->assertSent(function ($request) {
            $this->assertSame('https://statamic.example/moved', $request->url());
            foreach (['Authorization', 'Cookie', 'X-DiscussionBridge-Connection', 'X-DiscussionBridge-Secret'] as $header) {
                $this->assertFalse($request->hasHeader($header));
            }
            return true;
        });
    }

    public function test_owned_article_itself_cannot_be_hidden(): void
    {
        $page = str_replace('<article ', '<article hidden ', $this->profile->fixturePage);
        $this->http->fake(fn () => $this->http->response($page, 200, ['Content-Type' => 'text/html']));
        $this->expectException(CmsException::class);
        (new ToSourcePublicVerifier(new Configuration, $this->profile))->verify($this->projection, '2020-01-01T00:00:00.000Z');
    }

    public function test_public_raw_text_frame_cannot_borrow_an_unrelated_visible_article_context(): void
    {
        $page = '<html><body><article data-discussionbridge-source-profile="'.ToSourceArticleProfile::PROFILE.'">Unrelated visible decoy</article><script type="application/json">'.$this->profile->fixtureFrame.'</script></body></html>';
        $this->http->fake(fn () => $this->http->response($page, 200, ['Content-Type' => 'text/html']));
        $this->expectException(CmsException::class);
        (new ToSourcePublicVerifier(new Configuration, $this->profile))->verify($this->projection, '2020-01-01T00:00:00.000Z');
    }

    public function test_native_raw_text_frame_cannot_borrow_an_unrelated_visible_article_context(): void
    {
        $this->profile->fixturePage = '<html><body><article data-discussionbridge-source-profile="'.ToSourceArticleProfile::PROFILE.'">Unrelated visible decoy</article><script type="application/json">'.$this->profile->fixtureFrame.'</script></body></html>';
        $this->expectException(CmsException::class);
        $this->profile->expectedFrame($this->projection);
    }

    public static function activeInlineStyles(): array
    {
        return [
            ['display:none;display:block'], ['display:none!important;display:block!important'],
            ['display:block!important;display:none'], ['visibility:hidden;visibility:visible'],
            ['content-visibility:hidden;content-visibility:visible'],
            ["--sample:'display:none;';display:block"], ['/* display:none */ display:block'], ['di/**/splay:none'],
        ];
    }

    #[DataProvider('activeInlineStyles')]
    public function test_actual_native_and_public_verifier_preserve_active_inline_cascade_and_quoted_text(string $style): void
    {
        $this->profile->fixturePage = '<html><body><main style="'.htmlspecialchars($style, ENT_QUOTES, 'UTF-8').'">'.$this->profile->fixtureFrame.'</main></body></html>';
        $this->http->fake(fn () => $this->http->response($this->profile->fixturePage, 200, ['Content-Type' => 'text/html']));
        $proof = (new ToSourcePublicVerifier(new Configuration, $this->profile))->verify($this->projection, '2020-01-01T00:00:00.000Z');
        $this->assertSame(hash('sha256', $this->profile->fixtureFrame), $proof->native_publication_sha256);
    }

    public function test_inline_important_hidden_and_unknown_override_remain_conservative(): void
    {
        foreach (['display:none!important;display:block', 'display:none;display:unknown-value'] as $style) {
            $this->http->fake(fn () => $this->http->response('<html><body><main style="'.$style.'">'.$this->profile->fixtureFrame.'</main></body></html>', 200, ['Content-Type' => 'text/html']));
            try {
                (new ToSourcePublicVerifier(new Configuration, $this->profile))->verify($this->projection, '2020-01-01T00:00:00.000Z');
                $this->fail('A hidden/uncertain inline override qualified.');
            } catch (CmsException $error) {
                $this->assertSame('public_verification_failed', $error->failureCode);
            }
        }
    }

    public static function changedProof(): array { return [['body'], ['nonce'], ['duplicate']]; }

    #[DataProvider('changedProof')]
    public function test_existing_complete_frame_rejections_stay_effective(string $fault): void
    {
        $page = match ($fault) {
            'body' => str_replace('Complete é source.', 'Changed source.', $this->profile->fixturePage),
            'nonce' => str_replace($this->profile->markers($this->projection)[0], '<!--wrong-marker-->', $this->profile->fixturePage),
            default => $this->profile->fixturePage.$this->profile->fixturePage,
        };
        $this->http->fake(fn () => $this->http->response($page, 200, ['Content-Type' => 'text/html']));
        $this->expectException(CmsException::class);
        (new ToSourcePublicVerifier(new Configuration, $this->profile))->verify($this->projection, '2020-01-01T00:00:00.000Z');
    }
}

class ControlledSourceProofProfile extends ToSourceArticleProfile
{
    public string $fixtureFrame = '';
    public string $fixturePage = '';
    public function frame(array $projection): string { return $this->fixtureFrame; }
    public function renderPage($entry): string { return $this->fixturePage; }
}
