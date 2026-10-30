<?php
declare(strict_types=1);

// No Statamic/Composer/optional PHP extension needed for the shipped private codec.
require_once dirname(__DIR__).'/src/Cms/Shared/CmsCodec.php';

use DiscussionBridge\CmsV4\Codec;
use DiscussionBridge\CmsV4\ValidationException;

$root = dirname(__DIR__);
if (class_exists('Composer\\InstalledVersions', false) || class_exists('Composer\\Autoload\\ClassLoader', false)) {
    throw new RuntimeException('Run this standalone check without a preloaded Composer host');
}
$beforeLoaders = count(spl_autoload_functions());
$codec = new Codec($root.'/src/Cms/Shared/cms-schema.json');
$composerProbes = 0;
for ($i = 0; $i < 3; $i++) {
    foreach (['Composer\\InstalledVersions', 'Composer\\Autoload\\ClassLoader'] as $class) {
        if (class_exists($class)) throw new RuntimeException('Private runtime intercepted global Composer');
        $composerProbes++;
    }
}
if (count(spl_autoload_functions()) !== $beforeLoaders + 1) throw new RuntimeException('Unexpected private loader registration');
require $root.'/src/Cms/Shared/idna/vendor/autoload.php';
if (count(spl_autoload_functions()) !== $beforeLoaders + 1) throw new RuntimeException('Private loader registered twice');
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
echo json_encode(['candidate' => Codec::CANDIDATE_ID, 'checks' => $checks, 'context_labels' => count($context->cases), 'standalone_composer_probes' => $composerProbes, 'global_composer_absent' => true, 'private_loader_once' => true, 'php' => PHP_VERSION, 'limitations' => 'Shipped codec only; no native platform, HTTP, persistence or installed acceptance'], JSON_THROW_ON_ERROR).PHP_EOL;
