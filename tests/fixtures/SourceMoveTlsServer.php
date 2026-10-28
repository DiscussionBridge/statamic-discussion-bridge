<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests\Fixtures;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Isolated TLS socket, ephemeral fixture CA; no installed/global trust change. */
class SourceMoveTlsServer
{
    public readonly string $directory;
    private Process $process;
    private int $port;

    public function __construct()
    {
        $this->directory = dirname(__DIR__, 2).'/.tmp/source-move-tls-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
        $configuration = $this->directory.'/openssl.cnf';
        file_put_contents($configuration, "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=Statamic isolated fixture\n[ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\n[server]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=DNS:statamic.example\n");
        $options = ['config' => $configuration, 'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];
        $caKey = openssl_pkey_new($options);
        $key = openssl_pkey_new($options);
        if ($caKey === false || $key === false) {
            throw new RuntimeException('Isolated TLS fixture key creation failed.');
        }
        $caCsr = openssl_csr_new(['commonName' => 'Statamic isolated fixture CA'], $caKey, $options);
        $csr = openssl_csr_new(['commonName' => 'statamic.example'], $key, $options);
        $ca = $caCsr === false ? false : openssl_csr_sign($caCsr, null, $caKey, 1, [...$options, 'x509_extensions' => 'ca'], 1);
        $cert = $csr === false || $ca === false ? false : openssl_csr_sign($csr, $ca, $caKey, 1, [...$options, 'x509_extensions' => 'server'], 2);
        if ($ca === false || $cert === false || ! openssl_x509_export($ca, $caPem)
            || ! openssl_x509_export($cert, $pem) || ! openssl_pkey_export($key, $private, null, $options)) {
            throw new RuntimeException('Isolated TLS fixture certificate creation failed.');
        }
        file_put_contents($this->directory.'/ca.pem', $caPem);
        file_put_contents($this->directory.'/server.pem', $pem.$private);
        file_put_contents($this->directory.'/routes.json', '{}');
        $ini = php_ini_loaded_file();
        $owner = bin2hex(random_bytes(32));
        $this->process = new Process([PHP_BINARY, '-c', $ini, dirname(__DIR__).'/fixtures/source-move-tls-server.php', $this->directory],
            dirname(__DIR__, 2), ['DB_SOURCE_TEST_OWNER' => $owner], null, 120);
        $this->process->start();
        $deadline = microtime(true) + 10;
        while (! is_file($this->directory.'/ready.json')) {
            if (! $this->process->isRunning() || microtime(true) >= $deadline) {
                $this->process->stop(1);
                throw new RuntimeException('Isolated TLS fixture did not start.');
            }
            usleep(20000);
        }
        $ready = json_decode(file_get_contents($this->directory.'/ready.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->port = $ready['port'];
        // Windows proc_open may report the command wrapper PID rather than
        // PHP's PID. Bind the actual child receipt to this live Process launch
        // with its unshared launch nonce; do not equate these two PID APIs.
        if (! $this->process->isRunning() || ! is_int($ready['pid']) || $ready['pid'] < 1
            || ! hash_equals($owner, $ready['owner'] ?? '') || $this->port < 1) {
            $this->process->stop(1);
            throw new RuntimeException('Isolated TLS fixture owner mismatch.');
        }
    }

    public function publish(string $url, string $html, int $status): void
    {
        $path = parse_url($url, PHP_URL_PATH);
        $target = $this->directory.'/page-'.hash('sha256', $path).'.html';
        file_put_contents($target, $html);
        $routes = json_decode(file_get_contents($this->directory.'/routes.json'), true, 512, JSON_THROW_ON_ERROR);
        $routes[$path] = ['file' => basename($target), 'status' => $status];
        file_put_contents($this->directory.'/routes.json', json_encode($routes, JSON_THROW_ON_ERROR));
    }

    public function request(string $url, array $headers = [], ?string $ca = null): ResponseInterface
    {
        $host = parse_url($url, PHP_URL_HOST);
        // Actual TLS/hostname verification remains enabled. Only transport
        // connection address is remapped to this independent loopback owner.
        $client = new Client(['handler' => new CurlHandler, 'verify' => $ca ?? $this->directory.'/ca.pem',
            'proxy' => '', 'allow_redirects' => false, 'http_errors' => false,
            'connect_timeout' => 3, 'timeout' => 5, 'cookies' => false,
            'curl' => [CURLOPT_CONNECT_TO => [$host.':443:127.0.0.1:'.$this->port]]]);
        return $client->request('GET', $url, ['headers' => $headers]);
    }

    public function requests(): array
    {
        $path = $this->directory.'/requests.jsonl';
        return is_file($path) ? array_map(fn ($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];
    }

    public function close(): void
    {
        file_put_contents($this->directory.'/stop', 'stop');
        // Only this owned live Process is stopped, never a saved/reused PID.
        $this->process->stop(1);
    }
}
