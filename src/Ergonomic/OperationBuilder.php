<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

use Gisl\Generated\OpenApi\Model\OperationDownload;
use Gisl\Generated\OpenApi\Model\WorkflowStatusResponse;
use Gisl\Sdk\Errors\GislTimeoutError;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\JobDefinitionPayload;
use Gisl\Sdk\OperationDef;
use Gisl\Sdk\Sources;
use Gisl\Sdk\UploadOptions;
use Gisl\Sdk\WorkflowCreatePayload;

/**
 * Operation-builder layer for the SDK ergonomic surface (PHP P2 /
 * 7QXkzoIi). Pattern-port of the TS reference at
 * `packages/typescript/src/builder.ts`.
 *
 * Composes a {@see GislClient} — does NOT subclass. Each
 * `$ergonomicClient->compress($input, $options)` (and the four sibling
 * factories on {@see \Gisl\Sdk\GislErgonomicClient}) returns an
 * `OperationBuilder`; calling `->run(RunOptions)` orchestrates the full
 * upload → createWorkflow → wait → getWorkflowDownloads → flat `Result`
 * projection chain. `->submit(SubmitOptions)` skips the wait + downloads
 * steps and returns a lighter {@see Handle} instead.
 *
 * Wire-truth boundaries:
 *  - `Result::$artifacts` is a FLAT projection of `WorkflowDownloadResponse`
 *    (`downloads[].files[]`) with `url` aliasing `downloadUrl`. Every other
 *    field is verbatim from `OperationDownload`.
 *  - `RunOptions::$onProgress` callbacks receive a **SDK-SYNTHESISED
 *    discriminated union**: `UploadProgressEvent` from
 *    `UploadOptions::onProgress` (byte-counter only, no wire field for it)
 *    and `ProcessingProgressEvent` projecting `SseOperationProgressData`
 *    verbatim. The `phase` discriminator is SDK-added; the wire does NOT
 *    carry it.
 *
 * The await-terminal trio (`consumeSseToTerminal`/`pollToTerminal`/
 * `awaitTerminal`) and the pure helpers (`nowMs`, `coerceString`,
 * `formatIso8601Utc`, etc.) live on {@see BuilderInternals} so
 * {@see MergeBuilder} can reuse them without duplication. The TS reference
 * mirrors this by `export`ing the same helpers from `builder.ts` for
 * `merge.ts` to consume.
 *
 * Differences from the TS reference, all PHP-idiomatic:
 *  - No `AbortSignal` analogue — PHP's PSR-18 surface has no equivalent;
 *    the only abort signal is the wall-clock `maxWait` deadline. A
 *    cancellation primitive lands in a follow-up (VOxtu0RZ-B2 / similar).
 *  - Input is `string` filesystem path only (matches existing
 *    `GislClient::uploadFile()`'s contract); no Blob/File analogue.
 *  - The SSE generator cannot be interrupted mid-frame on a blocking
 *    read. The deadline check fires between yielded frames, and the
 *    poll-fallback path is preferred for callers concerned about quiet
 *    streams (set `RunOptions::$useSSE = false`).
 */
final class OperationBuilder
{
    /**
     * @param array<string, mixed> $opOptions
     */
    public function __construct(
        private readonly GislClient $client,
        private readonly string $opType,
        private readonly string $input,
        private readonly array $opOptions,
    ) {
    }

    /**
     * Execute the operation end-to-end. Uploads the input, creates the
     * workflow, waits to a terminal status (via SSE with poll fallback),
     * fetches downloads, and projects to a flat {@see Result}. Throws
     * {@see GislTimeoutError} if `RunOptions::$maxWait` elapses before
     * the workflow reaches a terminal status.
     */
    public function run(RunOptions $options): Result
    {
        $deadlineMs = BuilderInternals::nowMs() + MaxWait::parse($options->maxWait);
        $onProgress = BuilderInternals::callableOrNull($options->onProgress, 'RunOptions::$onProgress');

        // 1. Upload — emits UploadProgressEvent from the byte counter.
        $uploadOpts = null;
        if ($onProgress !== null) {
            $uploadOpts = new UploadOptions(
                onProgress: static function (int $uploadedBytes, int $totalBytes) use ($onProgress): void {
                    $onProgress(new UploadProgressEvent($uploadedBytes, $totalBytes));
                },
            );
        }
        $uploadResp = $this->client->uploadFile($this->input, $uploadOpts);

        // Codex TS r2 medium 9a117f04eb59 — check the deadline AFTER upload
        // so a slow upload doesn't proceed to createWorkflow past the
        // caller's deadline.
        if (BuilderInternals::nowMs() >= $deadlineMs) {
            throw new GislTimeoutError(
                'Upload completed but maxWait elapsed before workflow could be created.',
            );
        }

        // 2. Build + create the workflow.
        $job = new JobDefinitionPayload(
            operations: [new OperationDef(type: $this->opType, options: $this->opOptions)],
            id: 'op',
            source: Sources::upload($uploadResp->getFileId() ?? ''),
        );
        $created = $this->client->createWorkflow(new WorkflowCreatePayload(jobs: [$job]));

        // 3. Wait to terminal status.
        $workflowId = $created->getWorkflowId() ?? '';
        $finalStatus = BuilderInternals::awaitTerminal(
            client: $this->client,
            workflowId: $workflowId,
            deadlineMs: $deadlineMs,
            onProgress: $onProgress,
            useSSE: $options->useSSE,
            pollIntervalMs: $options->pollIntervalMs,
        );

        // 4. Fetch downloads. Codex TS r1 medium 42a6ea3b6102 — the
        // maxWait deadline covers upload + create + wait + downloads, so
        // check the deadline before issuing the downloads request rather
        // than letting a slow getWorkflowDownloads silently exceed it.
        if (BuilderInternals::nowMs() >= $deadlineMs) {
            throw new GislTimeoutError(
                "Workflow {$workflowId} reached terminal status but maxWait elapsed before downloads could be fetched.",
            );
        }
        $downloads = $this->client->getWorkflowDownloads($workflowId);

        return self::projectResult($finalStatus, $downloads->getDownloads() ?? [], $this->opOptions);
    }

    /**
     * Fire-and-forget: upload the input + create the workflow with a
     * `callback_url` wired to {@see SubmitOptions::$webhook}, then
     * return a {@see Handle} (workflowId + webhookSecret when the
     * server returned one) without waiting. The webhook receives
     * completion notification and the secret is the HMAC-verifier seed.
     */
    public function submit(SubmitOptions $options): Handle
    {
        $uploadResp = $this->client->uploadFile($this->input);

        $job = new JobDefinitionPayload(
            operations: [new OperationDef(type: $this->opType, options: $this->opOptions)],
            id: 'op',
            source: Sources::upload($uploadResp->getFileId() ?? ''),
        );
        $payload = new WorkflowCreatePayload(
            jobs: [$job],
            callbackUrl: $options->webhook,
        );
        $created = $this->client->createWorkflow($payload);

        return new Handle(
            workflowId: $created->getWorkflowId() ?? '',
            webhookSecret: $created->getWebhookSecret(),
        );
    }

    /**
     * Fan-out chain scaffold. Today this returns a {@see MapEachBuilder}
     * whose `run()` runs the parent builder + fans the fn out over each
     * resulting {@see Artifact}. See {@see MapEachBuilder}'s class-level
     * docblock for the KNOWN LIMITATION carried over from TS T6 / P4.
     */
    public function mapEach(callable $fn): MapEachBuilder
    {
        return new MapEachBuilder($this, $fn);
    }

    /**
     * @param array<string, mixed>                                $appliedOptions
     * @param array<int, \Gisl\Generated\OpenApi\Model\JobDownload>|null $jobDownloads
     *   (The openapi-generator emits the parent collection as
     *   `array<JobDownload>` rather than `list<JobDownload>`; callers
     *   pass the getter's return value through unchanged.)
     *
     * @internal Exposed for {@see MapEachBuilder} + {@see MergeBuilder}
     *           reuse. Not part of the public API.
     */
    public static function projectResult(
        WorkflowStatusResponse $status,
        ?array $jobDownloads,
        array $appliedOptions,
    ): Result {
        $artifacts = [];
        foreach ($jobDownloads ?? [] as $job) {
            $jobId = BuilderInternals::coerceString($job->getJobId());
            $ref = BuilderInternals::coerceString($job->getRef());
            foreach ($job->getFiles() ?? [] as $file) {
                /** @var OperationDownload $file */
                $artifacts[] = new Artifact(
                    url: BuilderInternals::coerceString($file->getDownloadUrl()),
                    filename: BuilderInternals::coerceString($file->getFilename()),
                    sizeBytes: (int) ($file->getSizeBytes() ?? 0),
                    operation: BuilderInternals::coerceString($file->getOperation()),
                    operationId: BuilderInternals::coerceString($file->getOperationId()),
                    jobId: $jobId,
                    ref: $ref,
                    pageIndex: $file->getPageIndex(),
                    position: $file->getPosition(),
                );
            }
        }

        // Codex TS r1 medium 5d098e0f135e — error diagnostics live on
        // OperationResponse.errorCode/errorMessage, NOT on JobResponse.
        $jobs = [];
        foreach ($status->getJobs() ?? [] as $j) {
            $jobOps = [];
            foreach ($j->getOperations() ?? [] as $op) {
                $jobOps[] = new OperationBreakdown(
                    id: BuilderInternals::coerceString($op->getId()),
                    type: BuilderInternals::coerceString($op->getType()),
                    status: BuilderInternals::coerceString($op->getStatus()),
                    progress: $op->getProgress() !== null ? (float) $op->getProgress() : null,
                    errorCode: $op->getErrorCode(),
                    errorMessage: $op->getErrorMessage(),
                );
            }
            $jobs[] = new JobBreakdown(
                jobId: BuilderInternals::coerceString($j->getJobId()),
                ref: BuilderInternals::coerceString($j->getRef()),
                status: BuilderInternals::coerceString($j->getStatus()),
                operations: $jobOps,
            );
        }

        $createdAtStr = BuilderInternals::formatIso8601Utc($status->getCreatedAt());
        $updatedAtStr = BuilderInternals::formatIso8601Utc($status->getUpdatedAt());

        return new Result(
            workflowId: BuilderInternals::coerceString($status->getWorkflowId()),
            status: BuilderInternals::coerceString($status->getStatus()),
            createdAt: $createdAtStr,
            updatedAt: $updatedAtStr,
            artifacts: $artifacts,
            jobs: $jobs,
            url: \count($artifacts) === 1 ? $artifacts[0]->url : null,
            resolvedOptions: new ResolvedOptions(
                preset: null,
                applied: $appliedOptions,
                overrides: [],
                presetVersion: '1.0',
            ),
        );
    }
}
