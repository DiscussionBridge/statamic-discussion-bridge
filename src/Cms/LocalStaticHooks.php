<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** Explicit local native commands. No receiver-selected argv, shell or credentials. */
class LocalStaticHooks
{
    public function __construct(private readonly PrivateState $store, private readonly CmsClient $client,
        private readonly StaticArtifact $artifacts)
    {
    }

    public function configured(): void
    {
        foreach (['deploy', 'reconcile', 'restore'] as $operation) {
            $this->configuration($operation);
        }
    }

    private function configuration(string $operation): array
    {
        $hook = config('discussionbridge.cms_static_hooks.'.$operation);
        if (! is_array($hook) || array_diff(array_keys($hook), ['argv', 'cwd', 'timeout_seconds']) !== []
            || ! isset($hook['argv'], $hook['cwd'], $hook['timeout_seconds']) || ! is_array($hook['argv'])
            || ! array_is_list($hook['argv']) || $hook['argv'] === [] || ! is_int($hook['timeout_seconds'])
            || $hook['timeout_seconds'] < 1 || $hook['timeout_seconds'] > 3600) {
            throw new CmsException('presentation_unsupported');
        }
        foreach ($hook['argv'] as $argument) {
            if (! is_string($argument) || $argument === '' || str_contains($argument, "\0")) {
                throw new CmsException('presentation_unsupported');
            }
        }
        $executable = $hook['argv'][0];
        if ((! str_starts_with($executable, '/') && preg_match('/\A[A-Za-z]:[\\\\\/]/', $executable) !== 1)
            || ! is_file($executable) || is_link($executable)) {
            throw new CmsException('presentation_unsupported');
        }
        $hook['cwd'] = $this->artifacts->directory($hook['cwd']);
        return $hook;
    }

    /** Pending deployment is reconciled by its exact command identity, never rerun blindly. */
    public function deploy(array &$state, string $transactionId, callable $heartbeat): array
    {
        return $this->deployFor($transactionId, $state['static'][$transactionId],
            function () use (&$state) { $this->store->save($state); }, $heartbeat);
    }

    public function deployFor(string $transactionId, array &$transaction, callable $persist, callable $heartbeat): array
    {
        $this->artifacts->assertCapture($transaction['artifact']);
        if (isset($transaction['commands']['deploy'])) {
            $command = $transaction['commands']['deploy'];
            if (isset($command['receipt'])) {
                return $this->receipt($command['receipt'], $transactionId, $command['command_id'], $transaction, false);
            }
            return $this->executeFor($transactionId, $transaction, $persist, 'reconcile', $heartbeat, $command['command_id']);
        }
        try {
            return $this->executeFor($transactionId, $transaction, $persist, 'deploy', $heartbeat);
        } catch (CmsException $error) {
            if ($error->failureCode !== 'native_restore_required') {
                throw $error;
            }
            // A failed/lost local receipt is not permission to deploy again.
            // The configured native owner must prove the first command's result.
            return $this->executeFor($transactionId, $transaction, $persist, 'reconcile', $heartbeat,
                $transaction['commands']['deploy']['command_id']);
        }
    }

    public function restore(array &$state, string $transactionId, callable $heartbeat): array
    {
        return $this->restoreFor($transactionId, $state['static'][$transactionId],
            function () use (&$state) { $this->store->save($state); }, $heartbeat);
    }

    public function restoreFor(string $transactionId, array &$transaction, callable $persist, callable $heartbeat): array
    {
        if (! isset($transaction['previous_artifact'])) {
            throw new CmsException('native_restore_required');
        }
        $this->artifacts->assertCapture($transaction['previous_artifact']);
        if (isset($transaction['commands']['restore'])) {
            $command = $transaction['commands']['restore'];
            if (isset($command['receipt'])) {
                return $this->receipt($command['receipt'], $transactionId, $command['command_id'], $transaction, true);
            }
            return $this->executeFor($transactionId, $transaction, $persist, 'reconcile', $heartbeat, $command['command_id'], true);
        }
        return $this->executeFor($transactionId, $transaction, $persist, 'restore', $heartbeat, null, true);
    }

    /** Read only: validate retained command outcomes; never invoke a reconciliation command. */
    public function observe(string $transactionId, array $transaction): string
    {
        $commands = $transaction['commands'] ?? [];
        foreach ($commands as $command) {
            if (in_array($command['state'] ?? null, ['intent', 'running'], true)) return 'active';
            if (($command['state'] ?? null) !== 'reconciled' || ! isset($command['receipt'])) return 'uncertain';
            try {
                $restoring = ($command['operation'] ?? null) === 'restore'
                    || ($command['receipt']['outcome'] ?? null) === 'restored';
                $this->receipt($command['receipt'], $transactionId, $command['reconciles'] ?? $command['command_id'], $transaction, $restoring);
            } catch (\Throwable) { return 'uncertain'; }
        }
        return $commands === [] ? 'none' : 'settled';
    }

    private function executeFor(string $transactionId, array &$transaction, callable $persist, string $operation, callable $heartbeat,
        ?string $reconcileId = null, bool $restoring = false): array
    {
        $hook = $this->configuration($operation);
        $commandId = (string) Str::uuid();
        $key = $operation === 'reconcile' ? $operation.':'.$commandId : $operation;
        $path = $this->store->path('static-command-'.$commandId.'.json');
        $identity = hash('sha256', json_encode($hook, JSON_THROW_ON_ERROR));
        $transaction['commands'][$key] = ['command_id' => $commandId, 'configuration_sha256' => $identity,
            'operation' => $operation, 'reconciles' => $reconcileId, 'state' => 'intent',
            'started_at' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.v\Z'), 'pid' => null];
        $persist();
        $previous = $transaction['previous_artifact'] ?? null;
        $environment = [
            'DB_STATIC_COMMAND_ID' => $commandId, 'DB_STATIC_TRANSACTION_ID' => $transactionId,
            'DB_STATIC_OPERATION' => $operation, 'DB_STATIC_RECONCILE_COMMAND_ID' => $reconcileId ?? '',
            'DB_STATIC_RECONCILE_OPERATION' => $restoring ? 'restore' : 'deploy',
            'DB_STATIC_ARTIFACT_DIRECTORY' => $transaction['artifact']['directory'],
            'DB_STATIC_ARTIFACT_SHA256' => $transaction['artifact']['sha256'],
            'DB_STATIC_PREVIOUS_DIRECTORY' => $previous['directory'] ?? '',
            'DB_STATIC_PREVIOUS_SHA256' => $previous['sha256'] ?? '', 'DB_STATIC_RECEIPT_FILE' => $path,
        ];
        $process = new Process($hook['argv'], $hook['cwd'], $environment, null, $hook['timeout_seconds']);
        $process->disableOutput();
        $heartbeat();
        try {
            $process->start();
            $transaction['commands'][$key]['pid'] = $process->getPid();
            $transaction['commands'][$key]['state'] = 'running';
            $persist();
            while ($process->isRunning()) {
                $process->checkTimeout();
                $heartbeat();
                usleep(100000);
            }
            $heartbeat();
            $transaction['commands'][$key]['exit_code'] = $process->getExitCode();
            $transaction['commands'][$key]['completed_at'] = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.v\Z');
            $transaction['commands'][$key]['state'] = 'uncertain';
            $persist();
            if (! $process->isSuccessful() || ! is_file($path) || is_link($path) || filesize($path) > 65536) {
                throw new CmsException('native_restore_required');
            }
            $bytes = file_get_contents($path);
            $result = $this->client->codec()->parseJson($bytes);
            $receipt = $this->receipt($result, $transactionId, $reconcileId ?? $commandId, $transaction, $restoring);
            $transaction['commands'][$key]['receipt'] = $receipt;
            $transaction['commands'][$key]['state'] = 'reconciled';
            if ($operation === 'reconcile') {
                $transaction['commands'][$restoring ? 'restore' : 'deploy']['receipt'] = $receipt;
                $transaction['commands'][$restoring ? 'restore' : 'deploy']['state'] = 'reconciled';
            }
            $persist();
            return $receipt;
        } catch (\Throwable $error) {
            if ($process->isRunning()) {
                // Only this live Process object is stopped. Never kill a saved
                // PID after restart, where process identity cannot be established.
                $process->stop(1);
            }
            $transaction['commands'][$key]['state'] = 'uncertain';
            $persist();
            throw $error instanceof CmsException ? $error : new CmsException('native_restore_required');
        }
    }

    private function receipt(array|\stdClass $value, string $transactionId, string $commandId, array $transaction, bool $restoring): array
    {
        $receipt = (array) $value;
        $fields = ['command_id', 'transaction_id', 'outcome', 'artifact_sha256', 'deployment_id', 'deployed_at'];
        $keys = array_keys($receipt);
        sort($keys);
        sort($fields);
        $artifact = $restoring ? $transaction['previous_artifact'] : $transaction['artifact'];
        if ($keys !== $fields || $receipt['command_id'] !== $commandId || $receipt['transaction_id'] !== $transactionId
            || $receipt['outcome'] !== ($restoring ? 'restored' : 'deployed') || $receipt['artifact_sha256'] !== $artifact['sha256']) {
            throw new CmsException('native_restore_required');
        }
        $this->client->codec()->validate('OpaqueId', $receipt['deployment_id']);
        $this->client->codec()->validate('Instant', $receipt['deployed_at']);
        if (CarbonImmutable::parse($receipt['deployed_at'])->lessThan(CarbonImmutable::parse($transaction['prepared_at']))
            || CarbonImmutable::parse($receipt['deployed_at'])->greaterThan(CarbonImmutable::now('UTC'))) {
            throw new CmsException('native_restore_required');
        }
        return $receipt;
    }
}
