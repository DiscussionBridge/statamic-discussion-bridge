<?php

// Independent loopback-only TLS owner. Serves exact fixture files; no CMS,
// receiver credentials, source writer or global trust modifications.
$directory = realpath($argv[1]);
if ($directory === false || is_link($directory) || ! str_starts_with(basename($directory), 'source-move-tls-')) {
    exit(2);
}
$context = stream_context_create(['ssl' => ['local_cert' => $directory.'/server.pem', 'verify_peer' => false,
    'allow_self_signed' => true, 'disable_compression' => true]]);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($socket === false) {
    exit(3);
}
$address = stream_socket_get_name($socket, false);
file_put_contents($directory.'/ready.json', json_encode(['port' => (int) substr(strrchr($address, ':'), 1),
    'pid' => getmypid(), 'owner' => getenv('DB_SOURCE_TEST_OWNER')], JSON_THROW_ON_ERROR));
$deadline = microtime(true) + 90;
while (! is_file($directory.'/stop') && microtime(true) < $deadline) {
    $peer = @stream_socket_accept($socket, 1, $remote);
    if ($peer === false) {
        continue;
    }
    stream_set_timeout($peer, 3);
    if (@stream_socket_enable_crypto($peer, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) {
        fclose($peer);
        continue;
    }
    $line = fgets($peer, 4096);
    $parts = explode(' ', trim($line === false ? '' : $line));
    $headers = [];
    $bytes = 0;
    while (($line = fgets($peer, 4096)) !== false && trim($line) !== '') {
        $bytes += strlen($line);
        if ($bytes > 16384 || ! str_contains($line, ':')) {
            break;
        }
        [$name, $value] = explode(':', $line, 2);
        $headers[strtolower($name)] = trim($value);
    }
    $credentialNames = array_values(array_intersect(array_keys($headers),
        ['authorization', 'cookie', 'x-discussionbridge-connection', 'x-discussionbridge-secret']));
    $target = $parts[1] ?? '';
    $routes = json_decode(file_get_contents($directory.'/routes.json'), true, 512, JSON_THROW_ON_ERROR);
    $route = $routes[$target] ?? null;
    $status = $credentialNames !== [] || ($parts[0] ?? null) !== 'GET' ? 403 : ($route['status'] ?? 404);
    $html = $route !== null && basename($route['file']) === $route['file'] ? file_get_contents($directory.'/'.$route['file']) : '';
    file_put_contents($directory.'/requests.jsonl', json_encode(['method' => $parts[0] ?? null, 'target' => $target,
        'host' => $headers['host'] ?? null, 'credential_names' => $credentialNames,
        'remote_loopback' => str_starts_with($remote, '127.0.0.1:'), 'status' => $status,
        'body_sha256' => hash('sha256', $html)], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
    fwrite($peer, 'HTTP/1.1 '.$status." Fixture\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Length: ".strlen($html)."\r\nConnection: close\r\n\r\n".$html);
    fclose($peer);
}
fclose($socket);
