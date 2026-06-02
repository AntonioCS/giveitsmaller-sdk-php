<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\FileFirst;

use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\Errors\GislNoSuchKeyError;
use Gisl\Sdk\FileFirst\FileInput;
use Gisl\Sdk\FileFirst\FilesRecipe;
use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\Ergonomic\PresetResolver;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\GislErgonomicClient;
use Gisl\Sdk\Tests\Unit\Ergonomic\GislErgonomicClientFactoryTestHelper;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * FF3a (u0hBt6fl) — the file-first {@see FilesRecipe} homogeneous fan-out:
 * clone-on-write immutability, multi-job lowering (one job per file, the
 * `file-{i}` id scheme, per-file media-hint), and the partitioned
 * {@see \Gisl\Sdk\FileFirst\RunResult} (string-index keys, one-failure-doesn't-
 * sink, resource-input unsupported). Mirrors the TS `file-first-files.test.ts`.
 */
final class FilesRecipeTest extends TestCase
{
    private const WORKFLOW_ID = '01936fb2-0000-7000-8000-000000000000';

    private HttpFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new HttpFactory();
    }

    /**
     * @param list<string> $paths
     */
    private function filesRecipe(array $paths): FilesRecipe
    {
        $inputs = array_map(static fn (string $p): FileInput => FileInput::path($p), $paths);
        return new FilesRecipe($inputs);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function jobs(FilesRecipe $recipe, array $fileIds): array
    {
        /** @var list<array<string, mixed>> $jobs */
        $jobs = $recipe->toWorkflowPayload($fileIds)->toWire()['jobs'];
        return $jobs;
    }

    // --- Immutability (clone-on-write) --------------------------------------

    #[Test]
    public function chaining_an_op_returns_a_new_files_recipe_and_does_not_mutate_the_original(): void
    {
        $base = $this->filesRecipe(['a.jpg', 'b.jpg']);
        $compressed = $base->compress(OptimizeFor::Balanced);

        self::assertSame(0, $base->stepCount(), 'the base fan-out is untouched');
        self::assertSame(1, $compressed->stepCount());
        self::assertNotSame($base, $compressed, 'each op returns a fresh FilesRecipe');
        self::assertSame(2, $compressed->inputCount(), 'the input list is preserved across the clone');
        self::assertSame(2, $base->inputCount());
    }

    #[Test]
    public function two_branches_off_one_base_are_independent(): void
    {
        $base = $this->filesRecipe(['a.mov', 'b.mov']);
        $branchA = $base->convert('mp4')->compress(OptimizeFor::Size);
        $branchB = $base->thumbnail(width: 320);

        self::assertSame(0, $base->stepCount());
        self::assertSame(2, $branchA->stepCount());
        self::assertSame(1, $branchB->stepCount());

        // Re-lower the base AFTER both branches are built — its jobs must still
        // carry ZERO operations (catches a shared-array mutation stepCount misses).
        foreach ($this->jobs($base, ['f0', 'f1']) as $job) {
            self::assertSame([], $job['operations'], 'the base fan-out still lowers to zero operations per job');
        }
        $branchAJob = $this->jobs($branchA, ['f0', 'f1'])[0];
        self::assertSame(['convert', 'compress'], array_column($branchAJob['operations'], 'type'));
    }

    // --- toWorkflowPayload — one job per file, id scheme, shared chain ------

    #[Test]
    public function lowering_emits_one_job_per_input_with_id_scheme_and_shared_chain(): void
    {
        $jobs = $this->jobs(
            $this->filesRecipe(['a.jpg', 'b.jpg', 'c.jpg'])->convert('webp'),
            ['file_0', 'file_1', 'file_2'],
        );

        self::assertCount(3, $jobs);
        self::assertSame(['file-0', 'file-1', 'file-2'], array_column($jobs, 'id'));
        foreach ($jobs as $i => $job) {
            self::assertSame(['type' => 'upload', 'file_id' => "file_{$i}"], $job['source']);
            // Every input gets the SAME lowered operations[].
            self::assertSame([['type' => 'convert', 'options' => ['format' => 'webp']]], $job['operations']);
            // Wire key order (id, source, operations) for cross-language JSON parity.
            self::assertSame(['id', 'source', 'operations'], array_keys($job));
        }
    }

    #[Test]
    public function compress_resolves_the_preset_per_file_so_different_media_diverge(): void
    {
        // a.jpg → image preset cell; clip.mov → video preset cell. The fan-out
        // must compose Recipe per input so each picks its own media-hint.
        $jobs = $this->jobs(
            $this->filesRecipe(['a.jpg', 'clip.mov'])->compress(OptimizeFor::Balanced),
            ['file_0', 'file_1'],
        );

        $imageExpected = PresetResolver::resolveCompress(
            media: 'image',
            presetDefaults: null,
            scopedDefaults: null,
            presetOverrides: null,
            optimize: OptimizeFor::Balanced,
            explicitOptions: [],
        )['wireOptions'];
        $videoExpected = PresetResolver::resolveCompress(
            media: 'video',
            presetDefaults: null,
            scopedDefaults: null,
            presetOverrides: null,
            optimize: OptimizeFor::Balanced,
            explicitOptions: [],
        )['wireOptions'];

        self::assertSame(['type' => 'compress', 'options' => $imageExpected], $jobs[0]['operations'][0]);
        self::assertSame(['type' => 'compress', 'options' => $videoExpected], $jobs[1]['operations'][0]);
        self::assertNotEquals(
            $jobs[0]['operations'][0]['options'],
            $jobs[1]['operations'][0]['options'],
            'image and video preset cells are distinct',
        );
    }

    #[Test]
    public function compress_rejects_an_unknown_optimize_string(): void
    {
        $this->expectException(GislConfigError::class);
        $this->filesRecipe(['a.jpg'])->compress('Smallest');
    }

    #[Test]
    public function single_input_fan_out_serialises_to_a_stable_json_string(): void
    {
        $json = \json_encode(
            $this->filesRecipe(['a.jpg'])->convert('webp')->toWorkflowPayload(['file_0'])->toWire(),
        );
        self::assertSame(
            '{"jobs":[{"id":"file-0","source":{"type":"upload","file_id":"file_0"},"operations":[{"type":"convert","options":{"format":"webp"}}]}]}',
            $json,
        );
    }

    // --- run() partition (string-index keys, one-failure-doesn't-sink) ------

    #[Test]
    public function run_partitions_all_completed_jobs_into_succeeded_keyed_by_index(): void
    {
        $http = $this->stubClient([
            $this->createResponse(),
            $this->sseResponse("event: workflow.completed\ndata: {\"status\":\"completed\"}\n\n"),
            $this->multiJobStatusResponse('completed', [
                ['ref' => 'file-0', 'status' => 'completed'],
                ['ref' => 'file-1', 'status' => 'completed'],
            ]),
            $this->multiJobDownloadsResponse([
                ['ref' => 'file-0', 'filename' => 'a.webp', 'size' => 10, 'url' => 'https://cdn/a.webp'],
                ['ref' => 'file-1', 'filename' => 'b.webp', 'size' => 20, 'url' => 'https://cdn/b.webp'],
            ]),
        ]);

        $client = $this->makeClient($http);
        $result = $client->files([FileInput::uploadId('id0'), FileInput::uploadId('id1')])
            ->compress()
            ->run();

        self::assertTrue($result->ok);
        self::assertSame([], $result->failed);
        self::assertSame(['0', '1'], array_map(static fn ($s) => $s->key, $result->succeeded));
        self::assertSame('a.webp', $result->byKey('0')->outputs[0]->filename);
        self::assertSame('b.webp', $result->byKey('1')->outputs[0]->filename);
        // The flat artifacts[] carries every job's outputs in job order.
        self::assertSame(['https://cdn/a.webp', 'https://cdn/b.webp'], array_map(static fn ($a) => $a->url, $result->artifacts));
    }

    #[Test]
    public function run_one_failing_job_does_not_sink_the_rest(): void
    {
        // file-1 fails; file-0 + file-2 complete; workflow partially_failed.
        $http = $this->stubClient([
            $this->createResponse(),
            $this->sseResponse("event: workflow.partially_failed\ndata: {\"status\":\"partially_failed\"}\n\n"),
            $this->multiJobStatusResponse('partially_failed', [
                ['ref' => 'file-0', 'status' => 'completed'],
                ['ref' => 'file-1', 'status' => 'failed', 'error' => 'codec exploded'],
                ['ref' => 'file-2', 'status' => 'completed'],
            ]),
            $this->multiJobDownloadsResponse([
                ['ref' => 'file-0', 'filename' => 'a.webp', 'size' => 10, 'url' => 'https://cdn/a.webp'],
                ['ref' => 'file-2', 'filename' => 'c.webp', 'size' => 30, 'url' => 'https://cdn/c.webp'],
            ]),
        ]);

        $client = $this->makeClient($http);
        $result = $client->files([
            FileInput::uploadId('id0'),
            FileInput::uploadId('id1'),
            FileInput::uploadId('id2'),
        ])->compress()->run();

        self::assertSame('partially_failed', $result->state);
        self::assertFalse($result->ok);
        self::assertSame(['0', '2'], array_map(static fn ($s) => $s->key, $result->succeeded));
        self::assertSame(['1'], array_map(static fn ($f) => $f->key, $result->failed));
        // The failed item carries THAT job's first op error, scoped via the
        // PER-JOB status: "{jobStatus}: {message}".
        self::assertSame('failed: codec exploded', $result->failed[0]->error->getMessage());
        // The succeeded outputs are still resolvable; the failed job has none.
        self::assertSame('https://cdn/a.webp', $result->byKey('0')->outputs[0]->url);
        self::assertSame('https://cdn/c.webp', $result->byKey('2')->outputs[0]->url);
        self::assertSame(['https://cdn/a.webp', 'https://cdn/c.webp'], array_map(static fn ($a) => $a->url, $result->artifacts));
    }

    #[Test]
    public function run_all_jobs_failed_yields_failed_workflow_no_artifacts(): void
    {
        // The symmetric bookend to one-failure-doesn't-sink: when EVERY input
        // fails the workflow state is `failed` (NOT partially_failed). Both jobs
        // partition into failed (keyed "0"/"1"), each carrying ITS OWN scoped
        // error; nothing succeeds; the flat artifacts[] is empty; toArray() omits
        // the single-output `url` sugar (0 artifacts).
        $http = $this->stubClient([
            $this->createResponse(),
            $this->sseResponse("event: workflow.failed\ndata: {\"status\":\"failed\"}\n\n"),
            $this->multiJobStatusResponse('failed', [
                ['ref' => 'file-0', 'status' => 'failed', 'error' => 'codec exploded'],
                ['ref' => 'file-1', 'status' => 'failed', 'error' => 'unsupported pixel format'],
            ]),
            $this->multiJobDownloadsResponse([]),
        ]);

        $client = $this->makeClient($http);
        $result = $client->files([
            FileInput::uploadId('id0'),
            FileInput::uploadId('id1'),
        ])->compress()->run();

        self::assertSame('failed', $result->state);
        self::assertFalse($result->ok);
        self::assertSame([], $result->succeeded);
        self::assertSame(['0', '1'], array_map(static fn ($f) => $f->key, $result->failed));
        // Each failed item carries THAT job's first op error, scoped via its
        // PER-JOB status: "{jobStatus}: {message}".
        self::assertSame('failed: codec exploded', $result->failed[0]->error->getMessage());
        self::assertSame('failed: unsupported pixel format', $result->failed[1]->error->getMessage());
        self::assertSame([], $result->artifacts);
        // Zero artifacts → the single-output `url` sugar key is OMITTED entirely
        // (cross-language parity with TS's omit-when-undefined toJSON()).
        self::assertArrayNotHasKey('url', $result->toArray());
    }

    #[Test]
    public function by_key_resolves_string_index_and_throws_no_such_key_otherwise(): void
    {
        $http = $this->stubClient([
            $this->createResponse(),
            $this->sseResponse("event: workflow.completed\ndata: {\"status\":\"completed\"}\n\n"),
            $this->multiJobStatusResponse('completed', [
                ['ref' => 'file-0', 'status' => 'completed'],
                ['ref' => 'file-1', 'status' => 'completed'],
            ]),
            $this->multiJobDownloadsResponse([
                ['ref' => 'file-0', 'filename' => 'a.webp', 'size' => 10, 'url' => 'https://cdn/a.webp'],
                ['ref' => 'file-1', 'filename' => 'b.webp', 'size' => 20, 'url' => 'https://cdn/b.webp'],
            ]),
        ]);

        $client = $this->makeClient($http);
        $result = $client->files([FileInput::uploadId('id0'), FileInput::uploadId('id1')])
            ->compress()
            ->run();

        // String-index addressing: "0" resolves; loose numeric forms do NOT.
        self::assertSame('0', $result->byKey('0')->key);
        $this->expectException(GislNoSuchKeyError::class);
        // '00' is NOT '0' — the partition keys are the verbatim (string) $i.
        $result->byKey('00');
    }

    #[Test]
    public function files_with_empty_list_throws_config_error_reason_no_inputs(): void
    {
        // A zero-input fan-out is a caller error — the guard fires synchronously,
        // before any upload/create ("one bad input doesn't sink the rest" is
        // meaningless with no inputs). The helper's transport throws on any I/O,
        // proving no request is issued.
        $client = GislErgonomicClientFactoryTestHelper::client();
        try {
            $client->files([]);
            self::fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            self::assertSame('no_inputs', $e->reason);
        }
    }

    #[Test]
    public function run_no_client_guard_throws_config_error_reason_no_client(): void
    {
        $bare = new FilesRecipe([FileInput::uploadId('id0')]);
        try {
            $bare->compress()->run();
            self::fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            self::assertSame('no_client', $e->getReason());
        }
    }

    #[Test]
    public function run_resource_input_arm_throws_resource_input_unsupported(): void
    {
        // A resource-stream input is not supported by the fan-out run() — it
        // must fail fast with a typed config error BEFORE any network call.
        $stream = \fopen('php://memory', 'rb');
        self::assertIsResource($stream);
        $http = $this->stubClient([]);   // empty queue — no request should fire
        $client = $this->makeClient($http);
        try {
            $client->files([FileInput::resource($stream)])->compress()->run();
            self::fail('expected GislConfigError');
        } catch (GislConfigError $e) {
            self::assertSame('resource_input_unsupported', $e->getReason());
        } finally {
            \fclose($stream);
        }
    }

    // ----------------------------------------------------------------------
    // Stub plumbing — mirrors RecipeRunTest.
    // ----------------------------------------------------------------------

    /**
     * @param list<ResponseInterface|\Throwable> $queue
     */
    private function stubClient(array $queue): ClientInterface
    {
        return new class ($queue) implements ClientInterface {
            /** @var list<ResponseInterface|\Throwable> */
            private array $queue;

            /**
             * @param list<ResponseInterface|\Throwable> $queue
             */
            public function __construct(array $queue)
            {
                $this->queue = $queue;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $next = \array_shift($this->queue);
                if ($next === null) {
                    throw new \RuntimeException('Stub PSR-18 client: response queue exhausted');
                }
                if ($next instanceof \Throwable) {
                    throw $next;
                }
                return $next;
            }
        };
    }

    private function makeClient(ClientInterface $http): GislErgonomicClient
    {
        // files() lives on GislErgonomicClient (the subclass), not the base
        // GislClient — the run tests drive the fan-out through it.
        return new GislErgonomicClient(
            config: new GislClientConfig(baseUrl: 'https://api.example.com', apiKey: 'sk_test'),
            httpClient: $http,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonResponse(int $status, array $body): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) \json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function createResponse(): ResponseInterface
    {
        return $this->jsonResponse(201, [
            'success' => true,
            'data' => ['workflow_id' => self::WORKFLOW_ID, 'status' => 'pending'],
        ]);
    }

    private function sseResponse(string $sse): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/event-stream'], $sse);
    }

    /**
     * @param list<array{ref: string, status: string, error?: string}> $jobs
     */
    private function multiJobStatusResponse(string $status, array $jobs): ResponseInterface
    {
        $jobsWire = [];
        foreach ($jobs as $i => $job) {
            $ops = [];
            if (isset($job['error'])) {
                $ops[] = ['error_message' => $job['error']];
            }
            $jobsWire[] = [
                'job_id' => \sprintf('01936fb2-00%02d-7000-8000-0000000000%02d', $i + 2, $i + 2),
                'ref' => $job['ref'],
                'status' => $job['status'],
                'operations' => $ops,
            ];
        }
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => ['workflow_id' => self::WORKFLOW_ID, 'status' => $status, 'jobs' => $jobsWire],
        ]);
    }

    /**
     * @param list<array{ref: string, filename: string, size: int, url: string}> $entries
     */
    private function multiJobDownloadsResponse(array $entries): ResponseInterface
    {
        $downloads = [];
        foreach ($entries as $i => $entry) {
            $downloads[] = [
                'job_id' => \sprintf('01936fb2-00%02d-7000-8000-0000000000%02d', $i + 2, $i + 2),
                'ref' => $entry['ref'],
                'files' => [
                    [
                        'operation' => 'compress',
                        'operation_id' => \sprintf('01936fb2-01%02d-7000-8000-0000000001%02d', $i, $i),
                        'filename' => $entry['filename'],
                        'size_bytes' => $entry['size'],
                        'download_url' => $entry['url'],
                    ],
                ],
            ];
        }
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => ['downloads' => $downloads],
        ]);
    }
}
