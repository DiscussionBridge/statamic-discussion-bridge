<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use DiscussionBridge\CmsV4\Codec;
use DiscussionBridge\CmsV4\ValidationException;
use PHPUnit\Framework\TestCase as PlainTestCase;

/** Exact shipped codec only; the native profile suites exercise native state. */
class SharedContractTest extends PlainTestCase
{
    private function codec(): Codec
    {
        require_once dirname(__DIR__).'/src/Cms/Shared/CmsCodec.php';

        return new Codec(dirname(__DIR__).'/src/Cms/Shared/cms-schema.json');
    }

    public function test_shipped_closure_matches_the_shared_member_ledger(): void
    {
        $root = dirname(__DIR__);
        $manifest = json_decode(file_get_contents($root.'/src/Cms/Shared/PROVENANCE.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('09d77c0209835c2d996aad294d161f167c7bd838', $manifest['source_commit']);
        foreach ($manifest['members'] as $member) {
            $file = $root.'/'.$member['target'];
            $this->assertFileExists($file);
            $this->assertSame($member['bytes'], filesize($file), $member['target']);
            $this->assertSame($member['sha256'], hash_file('sha256', $file), $member['target']);
        }
        $this->assertSame('discussionbridge-v4-core-20261029-r7', $this->codec()::CANDIDATE_ID);
    }

    public function test_actual_shipped_codec_applies_fixed_domain_policy_without_changing_input(): void
    {
        $codec = $this->codec();
        $fixture = json_decode(file_get_contents(__DIR__.'/fixtures/v4/url-domain-policy.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($fixture['cases'] as $case) {
            $input = $case['url'];
            try {
                $codec->validate('Url', $input);
                $this->assertTrue($case['accepted'], $case['id']);
                $this->assertSame($case['facts'], $codec->urlAuthority($input), $case['id']);
            } catch (ValidationException $error) {
                $this->assertFalse($case['accepted'], $case['id']);
                $this->assertSame('invalid_field', $error->validationCode, $case['id']);
            }
            $this->assertSame($case['url'], $input, $case['id']);
        }
    }

    public function test_contextj_occurrences_and_slashless_connection_origins_use_the_same_codec(): void
    {
        $codec = $this->codec();
        $fixture = json_decode(file_get_contents(__DIR__.'/fixtures/v4/url-contextj.json'), true, flags: JSON_THROW_ON_ERROR);
        $positive = json_decode(file_get_contents(__DIR__.'/fixtures/v4/positive-examples.json'), false, flags: JSON_THROW_ON_ERROR);
        $connection = array_values(array_filter($positive->fixtures, fn ($item) => $item->type === 'Connection'))[0]->value;
        foreach ($fixture['cases'] as $case) {
            foreach ([$case['label'].'.example', $case['asciiHost']] as $host) {
                $origin = 'https://'.$host;
                $input = clone $connection;
                $input->destination_origin = $origin;
                foreach (['Url' => $origin.'/', 'Connection' => $input] as $type => $value) {
                    $before = json_encode($value, JSON_THROW_ON_ERROR);
                    try {
                        $codec->validate($type, $value);
                        $this->assertTrue($case['accepted'], $case['id'].' '.$type);
                    } catch (ValidationException $error) {
                        $this->assertFalse($case['accepted'], $case['id'].' '.$type);
                        $this->assertSame('invalid_field', $error->validationCode, $case['id'].' '.$type);
                    }
                    $this->assertSame($before, json_encode($value, JSON_THROW_ON_ERROR), $case['id'].' '.$type);
                }
            }
        }
    }
}
