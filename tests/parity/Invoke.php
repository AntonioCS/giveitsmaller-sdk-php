<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Parity;

use Gisl\Generated\OpenApi\Model\AudioWatermarkDecodeRequest;
use Gisl\Generated\OpenApi\Model\ContactRequest;
use Gisl\Generated\OpenApi\Model\ExternalImportRequest;
use Gisl\Generated\OpenApi\Model\LoginUserRequest;
use Gisl\Generated\OpenApi\Model\MultipartInitiateRequestMetadataHint;
use Gisl\Sdk\CreditsUsageOptions;
use Gisl\Sdk\GetSchemaOptions;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\JobDefinitionPayload;
use Gisl\Sdk\OperationDef;
use Gisl\Sdk\UploadOptions;
use Gisl\Sdk\Webhook;
use Gisl\Sdk\WorkflowCreatePayload;
use GuzzleHttp\Psr7\HttpFactory;

/**
 * Maps a {@see Fixture} to the right {@see GislClient} (or {@see Webhook})
 * call.
 *
 * Mirrors `packages/typescript/tests/parity/invoke.ts`. The argument shape
 * for each method is fixed across runners: a fixture that authors
 * `args: [<email + password mapping>]` MUST resolve to the same call
 * (`login(LoginUserRequest)` here, `client.login({email, password})` in TS).
 *
 * Bytes args (`kind: bytes`) are materialised to a temporary file on disk
 * because PHP's {@see GislClient::uploadFile()} accepts a filesystem path,
 * not raw bytes. Temp files are tracked on the {@see InvokeResult} and
 * cleaned up by the caller after the test exits.
 */
final class Invoke
{
    /**
     * @param Fixture $fixture
     * @return InvokeResult
     */
    public static function run(Fixture $fixture, StubPsr18Client $stub): InvokeResult
    {
        if ($fixture->mode === Fixture::MODE_WEBHOOK) {
            return self::runWebhook($fixture);
        }

        $factory = new HttpFactory();
        $config = new GislClientConfig(
            baseUrl: 'https://api.test.example.com',
            apiKey: 'test-api-key',
            // Force deterministic part order for parity. The PHP SDK is
            // sequential in v0.x anyway but the config field is recorded.
            multipartConcurrency: 1,
        );
        $client = new GislClient(
            config: $config,
            httpClient: $stub,
            requestFactory: $factory,
            streamFactory: $factory,
        );

        $tempFiles = [];
        try {
            $result = self::dispatch($client, $fixture, $tempFiles);
            return new InvokeResult(
                returnValue: $result,
                sseEvents: null,
                thrown: null,
                tempFiles: $tempFiles,
            );
        } catch (\Throwable $thrown) {
            return new InvokeResult(
                returnValue: null,
                sseEvents: null,
                thrown: $thrown,
                tempFiles: $tempFiles,
            );
        }
    }

    /**
     * @param list<string> $tempFiles Tracked temp files for cleanup.
     */
    private static function dispatch(
        GislClient $client,
        Fixture $fixture,
        array &$tempFiles,
    ): mixed {
        $args = $fixture->args;
        $method = $fixture->sdkMethod;

        switch ($method) {
            case 'uploadFile':
                $first = $args[0] ?? null;
                if (!\is_array($first) || ($first['kind'] ?? null) !== 'bytes') {
                    throw new \RuntimeException("uploadFile fixture {$fixture->name}: first arg must be a bytes value");
                }
                /** @var array<string, mixed> $first */
                $bytes = BytesDecoder::decode($first, $fixture->absolutePath);
                $filename = isset($first['filename']) ? (string) $first['filename'] : 'fixture.bin';
                $tempPath = self::writeTemp($bytes, $filename);
                $tempFiles[] = $tempPath;

                $options = null;
                if (isset($args[1]) && \is_array($args[1])) {
                    /** @var array<string, mixed> $opts */
                    $opts = $args[1];
                    $hint = null;
                    if (isset($opts['metadataHint']) && \is_array($opts['metadataHint'])) {
                        /** @var array<string, mixed> $hintMap */
                        $hintMap = $opts['metadataHint'];
                        $hint = new MultipartInitiateRequestMetadataHint([
                            'duration_seconds' => $hintMap['durationSeconds'] ?? null,
                            'width' => $hintMap['width'] ?? null,
                            'height' => $hintMap['height'] ?? null,
                        ]);
                    }
                    // SDK-3 (Wb6ebOMM): resumeUploadId on UploadOptions
                    // wires the parity fixture to the resume branch.
                    $resumeUploadId = isset($opts['resumeUploadId']) && \is_string($opts['resumeUploadId'])
                        ? $opts['resumeUploadId']
                        : null;
                    $options = new UploadOptions(
                        metadataHint: $hint,
                        resumeUploadId: $resumeUploadId,
                    );
                }
                return $client->uploadFile($tempPath, $options);

            case 'createWorkflow':
                $first = $args[0] ?? null;
                if (!\is_array($first)) {
                    throw new \RuntimeException("createWorkflow fixture {$fixture->name}: first arg must be a mapping");
                }
                /** @var array<string, mixed> $first */
                return $client->createWorkflow(self::buildWorkflowPayload($first));

            case 'getWorkflowStatus':
                return $client->getWorkflowStatus(self::stringArg($args, 0, $fixture->name));

            case 'getWorkflowDownloads':
                return $client->getWorkflowDownloads(self::stringArg($args, 0, $fixture->name));

            case 'cancelWorkflow':
                return $client->cancelWorkflow(self::stringArg($args, 0, $fixture->name));

            case 'resumeWorkflow':
                return $client->resumeWorkflow(self::stringArg($args, 0, $fixture->name));

            case 'retryOperation':
                return $client->retryOperation(self::stringArg($args, 0, $fixture->name));

            case 'getMetadata':
                return $client->getMetadata(self::stringArg($args, 0, $fixture->name));

            case 'streamEvents':
                // streamEvents returns Generator<GislSseEvent>. The runner
                // collects them into a list to compare against the
                // fixture's expected event sequence.
                $generator = $client->streamEvents(self::stringArg($args, 0, $fixture->name));
                /** @var list<array<string, mixed>> $events */
                $events = [];
                foreach ($generator as $event) {
                    $events[] = ['event' => $event->event, 'data' => $event->data];
                }
                return $events;

            case 'login':
                $first = $args[0] ?? null;
                if (!\is_array($first)) {
                    throw new \RuntimeException("login fixture {$fixture->name}: first arg must be a mapping");
                }
                /** @var array<string, mixed> $first */
                return $client->login(new LoginUserRequest([
                    'email' => $first['email'] ?? null,
                    'password' => $first['password'] ?? null,
                ]));

            case 'logout':
                $client->logout();
                return null;

            case 'submitContact':
                $first = $args[0] ?? null;
                if (!\is_array($first)) {
                    throw new \RuntimeException("submitContact fixture {$fixture->name}: first arg must be a mapping");
                }
                /** @var array<string, mixed> $first */
                $client->submitContact(new ContactRequest([
                    'name' => $first['name'] ?? null,
                    'email' => $first['email'] ?? null,
                    'subject' => $first['subject'] ?? null,
                    'message' => $first['message'] ?? null,
                    'website' => $first['website'] ?? null,
                ]));
                return null;

            case 'getCreditsBalance':
                return $client->getCreditsBalance();

            case 'getCreditsUsage':
                if (!isset($args[0])) {
                    return $client->getCreditsUsage(null);
                }
                $first = $args[0];
                if (!\is_array($first)) {
                    throw new \RuntimeException(
                        "getCreditsUsage fixture {$fixture->name}: first arg must be a mapping or omitted",
                    );
                }
                /** @var array<string, mixed> $first */
                $limit = isset($first['limit']) ? (int) $first['limit'] : null;
                $offset = isset($first['offset']) ? (int) $first['offset'] : null;
                return $client->getCreditsUsage(new CreditsUsageOptions(limit: $limit, offset: $offset));

            case 'getSchema':
                if (!isset($args[0])) {
                    return $client->getSchema(null);
                }
                $first = $args[0];
                if (!\is_array($first)) {
                    throw new \RuntimeException(
                        "getSchema fixture {$fixture->name}: first arg must be a mapping or omitted",
                    );
                }
                /** @var array<string, mixed> $first */
                return $client->getSchema(new GetSchemaOptions(
                    mimeType: isset($first['mimeType']) ? (string) $first['mimeType'] : null,
                    operation: isset($first['operation']) ? (string) $first['operation'] : null,
                    ifNoneMatch: isset($first['ifNoneMatch']) ? (string) $first['ifNoneMatch'] : null,
                    ifModifiedSince: isset($first['ifModifiedSince']) ? (string) $first['ifModifiedSince'] : null,
                ));

            case 'probeUpload':
                return $client->probeUpload(self::stringArg($args, 0, $fixture->name));

            case 'preflightClips':
                $first = $args[0] ?? null;
                if (!\is_array($first) || !\array_is_list($first)) {
                    throw new \RuntimeException(
                        "preflightClips fixture {$fixture->name}: first arg must be a list",
                    );
                }
                $ids = [];
                foreach ($first as $id) {
                    $ids[] = (string) $id;
                }
                return $client->preflightClips($ids);

            case 'decodeAudioWatermark':
                $first = $args[0] ?? null;
                if (!\is_array($first)) {
                    throw new \RuntimeException(
                        "decodeAudioWatermark fixture {$fixture->name}: first arg must be a mapping",
                    );
                }
                /** @var array<string, mixed> $first */
                $awData = ['file_id' => $first['fileId'] ?? null];
                // Only include method_hint when the fixture explicitly sets
                // it — the model's constructor defaults `methodHint` to
                // `'auto'` via setIfExists, so passing `null` would force
                // an unwanted wire emit. Mirrors the TS reference where the
                // typed argument leaves `methodHint` undefined when the
                // caller omits it.
                if (\array_key_exists('methodHint', $first)) {
                    $awData['method_hint'] = $first['methodHint'];
                }
                return $client->decodeAudioWatermark(new AudioWatermarkDecodeRequest($awData));

            case 'createExternalImport':
                $first = $args[0] ?? null;
                if (!\is_array($first)) {
                    throw new \RuntimeException(
                        "createExternalImport fixture {$fixture->name}: first arg must be a mapping",
                    );
                }
                /** @var array<string, mixed> $first */
                return $client->createExternalImport(new ExternalImportRequest([
                    'url' => $first['url'] ?? null,
                    'provider_hint' => $first['providerHint'] ?? null,
                    'password' => $first['password'] ?? null,
                ]));

            case 'waitForWorkflow':
                return $client->waitForWorkflow(self::stringArg($args, 0, $fixture->name));

            // SDK-3 (Wb6ebOMM) resume-support endpoints.
            case 'getUploadStatus':
                return $client->getUploadStatus(self::stringArg($args, 0, $fixture->name));

            case 'presignParts':
                $uploadId = self::stringArg($args, 0, $fixture->name);
                $partsRaw = $args[1] ?? null;
                $totalParts = $args[2] ?? null;
                if (!\is_array($partsRaw) || !\is_int($totalParts)) {
                    throw new \RuntimeException(
                        "presignParts fixture {$fixture->name}: args must be (uploadId:string, partNumbers:list<int>, totalParts:int)",
                    );
                }
                /** @var list<int> $parts */
                $parts = [];
                foreach ($partsRaw as $n) {
                    if (!\is_int($n)) {
                        throw new \RuntimeException(
                            "presignParts fixture {$fixture->name}: partNumbers entries must be ints",
                        );
                    }
                    $parts[] = $n;
                }
                return $client->presignParts($uploadId, $parts, $totalParts);

            case 'keepaliveUpload':
                return $client->keepaliveUpload(self::stringArg($args, 0, $fixture->name));
        }

        throw new \RuntimeException("Invoke: unsupported method \"{$method}\" for fixture {$fixture->name}");
    }

    /**
     * @param array<string, mixed> $payload Plain-array fixture form.
     */
    private static function buildWorkflowPayload(array $payload): WorkflowCreatePayload
    {
        $jobsRaw = $payload['jobs'] ?? [];
        if (!\is_array($jobsRaw)) {
            throw new \RuntimeException('createWorkflow: jobs must be a list');
        }
        $jobs = [];
        foreach ($jobsRaw as $jobRaw) {
            if (!\is_array($jobRaw)) {
                throw new \RuntimeException('createWorkflow: each job must be a mapping');
            }
            /** @var array<string, mixed> $jobRaw */
            $jobs[] = self::buildJob($jobRaw);
        }

        /** @var list<array{from: string, to: string}>|null $edges */
        $edges = null;
        if (isset($payload['workflow_edges']) && \is_array($payload['workflow_edges'])) {
            $edges = [];
            foreach ($payload['workflow_edges'] as $e) {
                if (\is_array($e) && isset($e['from'], $e['to'])) {
                    $edges[] = ['from' => (string) $e['from'], 'to' => (string) $e['to']];
                }
            }
        }

        $callbackEvents = null;
        if (isset($payload['callback_events']) && \is_array($payload['callback_events'])) {
            $callbackEvents = [];
            foreach ($payload['callback_events'] as $ev) {
                $callbackEvents[] = (string) $ev;
            }
        }

        return new WorkflowCreatePayload(
            jobs: $jobs,
            workflowEdges: $edges,
            callbackUrl: isset($payload['callback_url']) ? (string) $payload['callback_url'] : null,
            callbackEvents: $callbackEvents,
            exportPayload: isset($payload['export']) && \is_array($payload['export'])
                ? self::asAssoc($payload['export'])
                : null,
            delivery: isset($payload['delivery']) && \is_array($payload['delivery'])
                ? self::asAssoc($payload['delivery'])
                : null,
            processing: isset($payload['processing']) && \is_array($payload['processing'])
                ? self::asAssoc($payload['processing'])
                : null,
        );
    }

    /**
     * @param array<string, mixed> $job
     */
    private static function buildJob(array $job): JobDefinitionPayload
    {
        $opsRaw = $job['operations'] ?? [];
        if (!\is_array($opsRaw)) {
            throw new \RuntimeException('createWorkflow: operations must be a list');
        }
        $ops = [];
        foreach ($opsRaw as $opRaw) {
            if (!\is_array($opRaw)) {
                throw new \RuntimeException('createWorkflow: each operation must be a mapping');
            }
            $type = isset($opRaw['type']) ? (string) $opRaw['type'] : '';
            $options = null;
            if (isset($opRaw['options']) && \is_array($opRaw['options'])) {
                $options = self::asAssoc($opRaw['options']);
            }
            $ops[] = new OperationDef(type: $type, options: $options);
        }

        /** @var array<string, mixed>|null $source */
        $source = null;
        if (isset($job['source']) && \is_array($job['source'])) {
            $source = self::asAssoc($job['source']);
        }
        /** @var list<array<string, mixed>>|null $inputs */
        $inputs = null;
        if (isset($job['inputs']) && \is_array($job['inputs'])) {
            $inputs = [];
            foreach ($job['inputs'] as $input) {
                if (\is_array($input)) {
                    $inputs[] = self::asAssoc($input);
                }
            }
        }

        return new JobDefinitionPayload(
            operations: $ops,
            id: isset($job['id']) ? (string) $job['id'] : null,
            source: $source,
            inputs: $inputs,
            deliver: isset($job['deliver']) ? (bool) $job['deliver'] : null,
            // Preserve explicit `false` separately from omission — the API
            // server defaults to `false` per JobDefinition.php:20, so the
            // SDK only emits the field when the fixture sets it.
            skipCompression: \array_key_exists('skip_compression', $job)
                ? (bool) $job['skip_compression']
                : null,
        );
    }

    /**
     * Deep-copy a fixture-shape associative array verbatim. Used for top-
     * level workflow-create blocks (export, delivery, processing, source,
     * operation options) where the wire shape IS the fixture shape and no
     * key conversion is needed.
     *
     * @return array<string, mixed>
     */
    private static function asAssoc(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $k => $v) {
            $key = (string) $k;
            $out[$key] = \is_array($v) ? self::deepCopy($v) : $v;
        }
        return $out;
    }

    /**
     * Recursive companion to {@see asAssoc} that preserves list-vs-assoc
     * shape on nested arrays — fixture nested arrays are typically lists
     * (e.g. workflow_edges, callback_events) and JSON-encoding a numeric-
     * keyed assoc would emit `{"0":...}` instead of a JSON array.
     *
     * @return array<string, mixed>|list<mixed>
     */
    private static function deepCopy(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }
        if (\array_is_list($value)) {
            $list = [];
            foreach ($value as $v) {
                $list[] = \is_array($v) ? self::deepCopy($v) : $v;
            }
            return $list;
        }
        $assoc = [];
        foreach ($value as $k => $v) {
            $assoc[(string) $k] = \is_array($v) ? self::deepCopy($v) : $v;
        }
        return $assoc;
    }

    /**
     * @param list<mixed> $args
     */
    private static function stringArg(array $args, int $index, string $fixtureName): string
    {
        if (!isset($args[$index]) || !\is_string($args[$index])) {
            throw new \RuntimeException(
                "Invoke: fixture {$fixtureName} arg #{$index} must be a string",
            );
        }
        return $args[$index];
    }

    /**
     * Write fixture bytes to a temp file whose basename matches the fixture's
     * `filename` — the SDK's `singleShotUpload`/`multipartUpload` path uses
     * `basename($filePath)` for the multipart form-data `filename`, so the
     * captured part filename must match the fixture's `filename` exactly.
     *
     * Each fixture gets its own ephemeral subdirectory so concurrent tests
     * cannot collide on a shared filename.
     */
    private static function writeTemp(string $bytes, string $filename): string
    {
        $base = \basename($filename);
        if ($base === '' || $base === '.' || $base === '..') {
            $base = 'fixture.bin';
        }
        $dir = \sys_get_temp_dir() . '/gisl-parity-' . \bin2hex(\random_bytes(6));
        if (!\mkdir($dir, 0700, true) && !\is_dir($dir)) {
            throw new \RuntimeException("Invoke: failed to mkdir {$dir}");
        }
        $path = $dir . '/' . $base;
        if (\file_put_contents($path, $bytes) === false) {
            throw new \RuntimeException("Invoke: failed to write {$path}");
        }
        return $path;
    }

    private static function runWebhook(Fixture $fixture): InvokeResult
    {
        if ($fixture->webhook === null) {
            throw new \RuntimeException("webhook fixture {$fixture->name}: missing webhook block");
        }
        $w = $fixture->webhook;
        $secret = (string) $w['secret'];
        $body = (string) $w['body'];
        $expectedHex = (string) $w['expected_signature_hex'];
        $headerFormat = (string) ($w['header_format'] ?? 'sha256={hex}');

        // Independently compute the digest so two buggy SDKs sharing one
        // bug can't both pass. Mirror the TS reference's parity-property
        // assertion before round-tripping through Webhook::verify.
        $computedHex = \hash_hmac('sha256', $body, $secret);
        if ($computedHex !== $expectedHex) {
            throw new \RuntimeException(
                "[{$fixture->name}] webhook parity failure: computed HMAC {$computedHex} != "
                . "expected_signature_hex {$expectedHex}",
            );
        }

        $headerValue = \str_replace('{hex}', $computedHex, $headerFormat);
        try {
            $ok = Webhook::verify($secret, $headerValue, $body);
            return new InvokeResult(returnValue: $ok, sseEvents: null, thrown: null, tempFiles: []);
        } catch (\Throwable $e) {
            return new InvokeResult(returnValue: false, sseEvents: null, thrown: $e, tempFiles: []);
        }
    }
}
