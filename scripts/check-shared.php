<?php
declare(strict_types=1);

// No Statamic/Composer/optional PHP extension needed for the shipped private codec.
require_once dirname(__DIR__).'/src/Cms/Shared/CmsCodec.php';

use DiscussionBridge\CmsV4\Codec;
use DiscussionBridge\CmsV4\ValidationException;

$root = dirname(__DIR__);
$codec = new Codec($root.'/src/Cms/Shared/cms-schema.json');
$read = static fn (string $name) => json_decode(file_get_contents($root.'/tests/fixtures/v4/'.$name), false, flags: JSON_THROW_ON_ERROR);
$checks = 0;
$check = static function (string $type, mixed $input, bool $accepted, string $label) use ($codec, &$checks): void {
    $before = json_encode($input, JSON_THROW_ON_ERROR);
    try {
        $codec->validate($type, $input);
        if (! $accepted) throw new RuntimeException('Unexpected admission: '.$label.' '.$type);
    } catch (ValidationException $error) {
        if ($accepted || $error->validationCode !== 'invalid_field') throw new RuntimeException('Unexpected refusal: '.$label.' '.$type, previous: $error);
    }
    if (json_encode($input, JSON_THROW_ON_ERROR) !== $before) throw new RuntimeException('Changed input: '.$label);
    $checks++;
};
foreach ($read('url-domain-policy.json')->cases as $item) {
    $check('Url', $item->url, $item->accepted, $item->id);
    if ($item->accepted && $codec->urlAuthority($item->url) !== (array) $item->facts) throw new RuntimeException('Wrong authority facts: '.$item->id);
}
$connection = array_values(array_filter($read('positive-examples.json')->fixtures, static fn ($item) => $item->type === 'Connection'))[0]->value;
$context = $read('url-contextj.json');
foreach ($context->cases as $item) {
    foreach ([$item->label.'.example', $item->asciiHost] as $host) {
        $origin = 'https://'.$host;
        $input = clone $connection;
        $input->destination_origin = $origin;
        $check('Url', $origin.'/', $item->accepted, $item->id);
        $check('Connection', $input, $item->accepted, $item->id);
    }
}
foreach (['https://example.test', 'https://example.test/', 'https://é.example', 'https://é.example/'] as $url) {
    $input = clone $connection;
    $input->destination_origin = $url;
    $check('Connection', $input, true, 'slashless/slash origin');
}
foreach (['https://é.example/path', 'https://é.example/?', 'https://é.example/#fragment'] as $url) {
    $input = clone $connection;
    $input->destination_origin = $url;
    $check('Connection', $input, false, 'not origin-only');
}
echo json_encode(['candidate' => Codec::CANDIDATE_ID, 'checks' => $checks, 'context_labels' => count($context->cases), 'php' => PHP_VERSION, 'limitations' => 'Shipped codec only; no native platform, HTTP, persistence or installed acceptance'], JSON_THROW_ON_ERROR).PHP_EOL;
