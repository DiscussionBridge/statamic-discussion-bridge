<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

use DiscussionBridge\CmsV4\NoContent;
use Illuminate\Support\Str;
use stdClass;

/** Call only inside PrivateState's lock. Uncertain POSTs keep their exact identity. */
class ReplayJournal
{
    public function __construct(private readonly CmsClient $client, private readonly PrivateState $store)
    {
    }

    public function prepare(array &$state, string $key, string $route, array $path, stdClass $payload, bool $persist = true): stdClass
    {
        $codec = $this->client->codec();
        $prior = $state['mutations'][$key] ?? null;
        $body = clone $payload;
        $body->mutation_id = $prior['mutation_id'] ?? (string) Str::uuid();
        $codec->validateRoute($route, ['path' => $path, 'body' => $body]);
        $identity = $codec->semanticIdentity($body);
        if ($prior !== null && ($prior['route'] !== $route || $prior['path'] !== $path || $prior['identity'] !== $identity)) {
            throw new CmsException('mutation_conflict');
        }
        if ($prior === null) {
            $state['mutations'][$key] = ['route' => $route, 'path' => $path, 'mutation_id' => $body->mutation_id,
                'identity' => $identity, 'body' => $codec->encodeJson($body), 'result' => null];
            if ($persist) $this->store->save($state);
        }
        return $body;
    }

    public function mutate(array &$state, string $key, string $route, array $path, stdClass $payload): mixed
    {
        $codec = $this->client->codec();
        $body = $this->prepare($state, $key, $route, $path, $payload);
        $prior = $state['mutations'][$key];
        $this->rejectIfRecorded($state, $key, $route, $path, $payload);
        if ($prior['result'] !== null) {
            return $prior['result'] === '' ? NoContent::VALUE
                : $codec->decodeResponse($route, $prior['result'], $prior['http_status'] ?? $codec->route($route)['success_http'][0]);
        }
        try {
            $result = $this->client->request($route, $path, null, $body);
        } catch (DefinitiveRejection $error) {
            if ($error->routeId !== $route) {
                throw new CmsException('native_restore_required');
            }
            // Save before the worker can terminalize its SQL queue claim. This
            // is a rejection, NEVER a cached successful mutation response.
            $state['mutations'][$key]['rejection'] = ['route' => $route,
                'http_status' => $error->httpStatus, 'envelope' => $error->envelope, 'code' => $error->failureCode,
                'mutation_id' => $prior['mutation_id'], 'request_identity' => $prior['identity']];
            $this->store->save($state);
            throw $error;
        } catch (CmsException $error) {
            if ($error->failureCode === 'transport_timeout') {
                throw new UncertainMutation($error->failureCode, $error->httpStatus);
            }
            throw $error;
        }
        $state['mutations'][$key]['http_status'] = $this->client->responseStatus();
        $state['mutations'][$key]['result'] = $result === NoContent::VALUE ? '' : $codec->encodeJson($result);
        $this->store->save($state);
        return $result;
    }

    /** Local exact-request refusal; no HTTP or renewed mutation identity. */
    public function rejectIfRecorded(array $state, string $key, string $route, array $path, stdClass $payload): void
    {
        $prior = $state['mutations'][$key] ?? null;
        if ($prior === null || ! array_key_exists('rejection', $prior)) {
            return;
        }
        $codec = $this->client->codec();
        $body = clone $payload;
        $body->mutation_id = $prior['mutation_id'];
        $codec->validateRoute($route, ['path' => $path, 'body' => $body]);
        $rejection = $prior['rejection'];
        if ($route === 'V4-02' && is_array($rejection) && (! array_key_exists('envelope', $rejection) || $rejection['envelope'] === null)) {
            $this->invalidFieldWitness($state, $key, $payload);
            throw DefinitiveRejection::retainedInvalidField();
        }
        if (! is_array($rejection) || $prior['route'] !== $route || $prior['path'] !== $path
            || $prior['identity'] !== $codec->semanticIdentity($body)
            || ($rejection['route'] ?? null) !== $route
            || ($rejection['mutation_id'] ?? null) !== $prior['mutation_id']
            || ($rejection['request_identity'] ?? null) !== $prior['identity']
            || ($rejection['http_status'] ?? null) !== 422 || ! is_string($rejection['envelope'] ?? null)
            || $prior['result'] !== null) {
            throw new CmsException('native_restore_required');
        }
        try {
            $error = DefinitiveRejection::fromResponse($codec, $route,
                $codec->parseJson($rejection['envelope']), $rejection['http_status']);
        } catch (\Throwable) {
            throw new CmsException('native_restore_required');
        }
        throw $error;
    }

    /** Protected exact refusal witness; generic SQL status is deliberately not evidence. */
    public function invalidFieldWitness(array $state, string $key, stdClass $payload): array
    {
        $prior = $state['mutations'][$key] ?? null; $rejection = $prior['rejection'] ?? null;
        if (! is_array($prior) || ! is_array($rejection) || $prior['route'] !== 'V4-02' || $prior['path'] !== []
            || $prior['result'] !== null || ($rejection['route'] ?? null) !== 'V4-02' || ($rejection['http_status'] ?? null) !== 422
            || ($rejection['mutation_id'] ?? null) !== $prior['mutation_id'] || ($rejection['request_identity'] ?? null) !== $prior['identity']) {
            throw new CmsException('native_retry_requires_reconciliation');
        }
        $body = clone $payload; $body->mutation_id = $prior['mutation_id'];
        $this->client->codec()->validateRoute('V4-02', ['body' => $body]);
        if ($prior['identity'] !== $this->client->codec()->semanticIdentity($body)) throw new CmsException('mutation_conflict');
        if (array_key_exists('envelope', $rejection) && $rejection['envelope'] !== null) {
            if (! is_string($rejection['envelope'])) throw new CmsException('native_retry_requires_reconciliation');
            try { $error = DefinitiveRejection::fromResponse($this->client->codec(), 'V4-02',
                $this->client->codec()->parseJson($rejection['envelope']), 422); }
            catch (\Throwable) { throw new CmsException('native_retry_requires_reconciliation'); }
            if ($error->failureCode !== 'invalid_field') throw new CmsException('native_retry_requires_reconciliation');
            return ['rejection' => $rejection, 'unknown_fields' => ['raw_http_response']];
        }
        if (($rejection['code'] ?? null) !== 'invalid_field') throw new CmsException('native_retry_requires_reconciliation');
        return ['rejection' => $rejection, 'unknown_fields' => ['decoded_error', 'raw_http_response', 'correlation_id', 'unretained_error_fields']];
    }
}
