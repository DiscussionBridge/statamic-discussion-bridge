<?php

declare(strict_types=1);

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php prepare-ssg-benchmark.php <disposable-statamic-root> <page-count>\n");
    exit(2);
}

$root = realpath($argv[1]);
$count = filter_var($argv[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
if ($root === false || $count === false || ! is_file($root.'/.discussionbridge-benchmark-sandbox') || ! is_file($root.'/please')) {
    fwrite(STDERR, "Target must be a marked disposable Statamic application and count must be 1–10,000.\n");
    exit(2);
}

$collectionFile = $root.'/content/collections/discussionbridge_benchmark.yaml';
$entryDirectory = $root.'/content/collections/discussionbridge_benchmark';
if (file_exists($collectionFile) || is_dir($entryDirectory)) {
    fwrite(STDERR, "Benchmark collection already exists; refusing to overwrite it.\n");
    exit(2);
}
if (! mkdir($entryDirectory, 0700, true) && ! is_dir($entryDirectory)) {
    throw new RuntimeException('Could not create benchmark entry directory.');
}
file_put_contents($collectionFile, "title: DiscussionBridge benchmark\nroute: '/benchmark/{slug}'\n");

for ($index = 1; $index <= $count; $index++) {
    $number = str_pad((string) $index, 5, '0', STR_PAD_LEFT);
    $id = sprintf('10000000-0000-4000-8000-%012d', $index);
    file_put_contents($entryDirectory.'/page-'.$number.'.md', "---\ntitle: Benchmark page {$number}\nid: {$id}\ntemplate: default\n---\n\n# Benchmark page {$number}\n\nRepresentative static content for a native Statamic SSG build.\n");
}

echo json_encode(['fixture' => $entryDirectory, 'pages' => $count], JSON_THROW_ON_ERROR).PHP_EOL;
