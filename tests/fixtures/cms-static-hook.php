<?php

// Isolated native deployment-owner fixture. No CMS client or credentials.
$public = $argv[1];
$operation = getenv('DB_STATIC_OPERATION');
$command = getenv('DB_STATIC_COMMAND_ID');
$transaction = getenv('DB_STATIC_TRANSACTION_ID');
$statePath = dirname($public).'/'.basename($public).'-owner.json';
$restoring = $operation === 'restore' || $operation === 'reconcile' && getenv('DB_STATIC_RECONCILE_OPERATION') === 'restore';
if (($argv[2] ?? '') === 'restore-unavailable' && $restoring) {
    exit(7);
}
$source = getenv($restoring ? 'DB_STATIC_PREVIOUS_DIRECTORY' : 'DB_STATIC_ARTIFACT_DIRECTORY');
$manifest = static function (string $directory): string {
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isLink()) {
            exit(2);
        }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
        $files[$relative] = ['bytes' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getPathname())];
    }
    ksort($files, SORT_STRING);
    return hash('sha256', json_encode($files, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
};
if ($operation === 'reconcile') {
    $receipt = json_decode(file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    if ($receipt['command_id'] !== getenv('DB_STATIC_RECONCILE_COMMAND_ID') || $receipt['transaction_id'] !== $transaction
        || $manifest($public) !== $receipt['artifact_sha256']) {
        exit(3);
    }
} else {
    // Explicitly named isolated target, verified before this fixture deletes files.
    $root = realpath(dirname(__DIR__, 2).'/.tmp');
    $actual = realpath($public);
    if ($actual === false || ! str_starts_with(strtolower(str_replace('\\', '/', $actual)), strtolower(str_replace('\\', '/', $root)).'/cms-static-public-')) {
        exit(4);
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($public, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        if ($file->isLink()) {
            exit(5);
        }
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
        if ($file->isLink()) {
            exit(6);
        }
        $destination = $public.substr($file->getPathname(), strlen($source));
        if ($file->isDir()) {
            mkdir($destination, 0777, true);
        } else {
            copy($file->getPathname(), $destination);
        }
    }
    $receipt = ['command_id' => $command, 'transaction_id' => $transaction, 'outcome' => $restoring ? 'restored' : 'deployed',
        'artifact_sha256' => $manifest($public), 'deployment_id' => 'native-fixture:'.$command,
        'deployed_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z')];
    file_put_contents($statePath, json_encode($receipt, JSON_THROW_ON_ERROR));
    if (($argv[2] ?? '') === 'lost-deployment-receipt' && $operation === 'deploy' && ! is_file($statePath.'.lost')) {
        file_put_contents($statePath.'.lost', $command);
        exit(8);
    }
}
file_put_contents(getenv('DB_STATIC_RECEIPT_FILE'), json_encode($receipt, JSON_THROW_ON_ERROR));
