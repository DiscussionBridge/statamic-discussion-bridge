<?php
declare(strict_types=1);

// Read-only comparison of the ordinary Composer archive; never extracts it.
if ($argc !== 2 || ! is_file($argv[1])) throw new RuntimeException('Usage: php scripts/check-package.php COMPOSER_ARCHIVE.zip');
$root = dirname(__DIR__);
$archive = new ZipArchive;
if ($archive->open($argv[1]) !== true) throw new RuntimeException('Cannot open Composer archive');
$entries = [];
try {
    for ($i = 0; $i < $archive->numFiles; $i++) {
        $name = $archive->getNameIndex($i);
        if ($name === false || str_contains($name, '\\') || str_starts_with($name, '/') || str_contains($name, ':') || str_contains($name, "\0")) throw new RuntimeException('Unsafe archive member');
        $parts = explode('/', rtrim($name, '/'));
        if (array_filter($parts, static fn ($part) => $part === '' || $part === '.' || $part === '..')) throw new RuntimeException('Unsafe archive path');
        if (in_array($parts[0], ['vendor', 'node_modules', '.git', '.agents', '.tmp', '.artifacts', '.phpunit.cache'], true)) throw new RuntimeException('Unshippable root directory: '.$name);
        if (str_ends_with($name, '/')) continue;
        if (isset($entries[$name])) throw new RuntimeException('Duplicate archive member: '.$name);
        $archive->getExternalAttributesIndex($i, $opsys, $attributes);
        if ((($attributes >> 16) & 0170000) === 0120000) throw new RuntimeException('Symlink in Composer archive: '.$name);
        $file = realpath($root.'/'.$name);
        if ($file === false || ! is_file($file) || ! str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', realpath($root)).'/')) throw new RuntimeException('Archive member has no contained source: '.$name);
        $bytes = $archive->getFromIndex($i);
        if ($bytes === false || $bytes !== file_get_contents($file)) throw new RuntimeException('Archive/source bytes differ: '.$name);
        $entries[$name] = hash('sha256', $bytes);
    }
    // Root Composer vendor is excluded, but the pinned nested private closure
    // and every native installer/template/asset/database source must ship.
    foreach (['src', 'config', 'database', 'resources', 'starter-kit'] as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->isLink()) throw new RuntimeException('Symlink in package source');
            if (! $file->isFile()) continue;
            $name = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if (! isset($entries[$name])) throw new RuntimeException('Missing native package member: '.$name);
        }
    }
    $ledger = json_decode(file_get_contents($root.'/src/Cms/Shared/PROVENANCE.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach ($ledger['members'] as $member) {
        if (($entries[$member['target']] ?? null) !== $member['sha256']) throw new RuntimeException('Missing/changed shared member: '.$member['target']);
    }
    foreach (['composer.json', 'LICENSE', 'README.md', 'scripts/check-shared.php', 'scripts/shared-contract.mjs', 'scripts/build-browser-assets.mjs', 'scripts/managed-output.mjs', 'scripts/bundled-license.mjs'] as $required) {
        if (! isset($entries[$required])) throw new RuntimeException('Missing package control: '.$required);
    }
    // This exact bundled version keeps its required MIT notice in README.
    // Byte/source equality alone also accepted the former metadata-only notice.
    if (($entries['resources/dist/THIRD_PARTY_LICENSES/fastdom_1.0.12.txt'] ?? null) !== 'df2ae14f12f31864acef768195afe4584b08a257f25925f49884619279f02f37') {
        throw new RuntimeException('Missing or changed actual bundled FastDom copyright/permission notice');
    }
    echo json_encode(['archive' => basename($argv[1]), 'bytes' => filesize($argv[1]), 'sha256' => hash_file('sha256', $argv[1]), 'source_members' => count($entries), 'shared_members' => count($ledger['members']), 'all_member_bytes' => 'equal', 'limitations' => 'Ordinary archive/source agreement; not installation or native/public acceptance'], JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    $archive->close();
}
