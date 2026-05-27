<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

use Gisl\Generated\OpenApi\Model\OperationDownload;
use Gisl\Generated\OpenApi\Model\SseEventType;
use Gisl\Generated\OpenApi\Model\WorkflowStatusResponse;
use Gisl\Sdk\Errors\GislNetworkError;
use Gisl\Sdk\Errors\GislTimeoutError;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\GislSseEvent;
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
        $deadlineMs = self::nowMs() + MaxWait::parse($options->maxWait);
        $onProgress = self::callableOrNull($options->onProgress, 'RunOptions::$onProgress');

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
        if (self::nowMs() >= $deadlineMs) {
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
        $finalStatus = $this->awaitTerminal(
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
        if (self::nowMs() >= $deadlineMs) {
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
     * @internal Exposed only for {@see MapEachBuilder} reuse. Not part
     *           of the public API.
     */
    public static function projectResult(
        WorkflowStatusResponse $status,
        ?array $jobDownloads,
        array $appliedOptions,
    ): Result {
        $artifacts = [];
        foreach ($jobDownloads ?? [] as $job) {
            $jobId = self::coerceString($job->getJobId());
            $ref = self::coerceString($job->getRef());
            foreach ($job->getFiles() ?? [] as $file) {
                /** @var OperationDownload $file */
                $artifacts[] = new Artifact(
                    url: self::coerceString($file->getDownloadUrl()),
                    filename: self::coerceString($file->getFilename()),
                    sizeBytes: (int) ($file->getSizeBytes() ?? 0),
                    // Generated enum-class getters (declared as e.g.
                    // `\…\OperationType` in the @return but stored as
                    // raw string constants at runtime) need the helper
                    // — `(string)` would fail PHPStan's cast.string.
                    operation: self::coerceString($file->getOperation()),
                    operationId: self::coerceString($file->getOperationId()),
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
                    id: self::coerceString($op->getId()),
                    type: self::coerceString($op->getType()),
                    status: self::coerceString($op->getStatus()),
                    progress: $op->getProgress() !== null ? (float) $op->getProgress() : null,
                    errorCode: $op->getErrorCode(),
                    errorMessage: $op->getErrorMessage(),
                );
            }
            $jobs[] = new JobBreakdown(
                jobId: self::coerceString($j->getJobId()),
                ref: self::coerceString($j->getRef()),
                status: self::coerceString($j->getStatus()),
                operations: $jobOps,
            );
        }

        $createdAt = $status->getCreatedAt();
        $updatedAt = $status->getUpdatedAt();
        // Codex TS r2 medium 3d229f9bc1fb + codex PHP r1 medium d244cec6c9fa
        // — TS projects via `Date.toISOString()` which emits UTC with
        // millisecond precision + `Z` suffix (e.g. `2026-05-27T11:00:00.123Z`).
        // PHP `RFC3339` emits `+00:00` and drops millis — diverges from
        // the TS shape, breaks parity. Use {@see formatIso8601Utc()} to
        // match.
        $createdAtStr = self::formatIso8601Utc($createdAt);
        $updatedAtStr = self::formatIso8601Utc($updatedAt);

        return new Result(
            workflowId: self::coerceString($status->getWorkflowId()),
            status: self::coerceString($status->getStatus()),
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

    /**
     * @param \Closure(ProgressEvent): void|null $onProgress
     */
    private function awaitTerminal(
        string $workflowId,
        int $deadlineMs,
        ?\Closure $onProgress,
        bool $useSSE,
        ?int $pollIntervalMs,
    ): WorkflowStatusResponse {
        if ($useSSE) {
            try {
                return $this->consumeSseToTerminal($workflowId, $deadlineMs, $onProgress);
            } catch (GislTimeoutError $e) {
                // Caller-deadline elapsed during SSE — propagate directly,
                // do NOT fall back to poll (the deadline is already done).
                throw $e;
            } catch (GislNetworkError $e) {
                // PSR-18 transport failed mid-SSE — try poll.
            } catch (SseStreamEndedWithoutTerminal $e) {
                // Clean server close with no terminal frame — try poll.
            }
            // Anything else (GislApiError subclasses for 401/402/etc.,
            // caller `onProgress` exceptions, framework errors)
            // PROPAGATES. Specifically: `GislError extends
            // \RuntimeException`, so a bare `\RuntimeException` arm
            // would silently swallow auth/balance/feature errors and
            // re-issue the same doomed request via poll (codex r2 high
            // 93a6f1be1fcd / round-2 reaffirmation).
        }
        return $this->pollToTerminal($workflowId, $deadlineMs, $pollIntervalMs);
    }

    /**
     * @param \Closure(ProgressEvent): void|null $onProgress
     */
    private function consumeSseToTerminal(
        string $workflowId,
        int $deadlineMs,
        ?\Closure $onProgress,
    ): WorkflowStatusResponse {
        if (self::nowMs() >= $deadlineMs) {
            throw new GislTimeoutError(
                "Workflow {$workflowId} did not complete before maxWait deadline.",
            );
        }

        // Wire SSE event names use dot notation (per
        // `generated/php/openapi/lib/Model/SseEventType.php` and the
        // OpenAPI spec at v2.15.3). Hand-typing underscore strings
        // (`workflow_completed` etc.) silently mismatched the wire and
        // run() would never see a terminal SSE frame — fall through to
        // poll on every invocation. Codex r2 high 1a0/3a0 caught this.
        $terminalEvents = [
            SseEventType::WORKFLOW_COMPLETED,
            SseEventType::WORKFLOW_FAILED,
            SseEventType::WORKFLOW_PARTIALLY_FAILED,
        ];
        $events = $this->client->streamEvents($workflowId);
        foreach ($events as $event) {
            /** @var GislSseEvent $event */
            if ($onProgress !== null && $event->event === SseEventType::OPERATION_PROGRESS && \is_array($event->data)) {
                $onProgress(self::projectProcessingProgress($event->data));
            }
            if (\in_array($event->event, $terminalEvents, true)) {
                // The SSE event carries partial data; the status endpoint
                // is the canonical structured response.
                return $this->client->getWorkflowStatus($workflowId);
            }
            // Per-frame deadline check — the only way to abort a blocking
            // PHP SSE generator. A pathologically quiet server would still
            // keep us blocked until the next frame arrives.
            if (self::nowMs() >= $deadlineMs) {
                throw new GislTimeoutError(
                    "Workflow {$workflowId} did not complete before maxWait deadline.",
                );
            }
        }

        // Stream ended cleanly without terminal — the poll fallback in
        // awaitTerminal() takes over. Use a SEALED marker exception so
        // the caller's GislApiError / onProgress exceptions cannot be
        // confused with this transport-level outcome (codex r2 high
        // 93a6f1be1fcd reaffirmation).
        throw new SseStreamEndedWithoutTerminal(
            "SSE stream ended for workflow {$workflowId} without terminal event.",
        );
    }

    private function pollToTerminal(
        string $workflowId,
        int $deadlineMs,
        ?int $pollIntervalMs,
    ): WorkflowStatusResponse {
        // Codex TS r1 medium 89130e3ea75d — guard against 0/negative/NaN
        // pollIntervalMs that would hammer getWorkflowStatus.
        if ($pollIntervalMs === null) {
            $intervalMs = 2_000;
        } elseif ($pollIntervalMs < 100) {
            $intervalMs = 100;
        } else {
            $intervalMs = $pollIntervalMs;
        }

        $terminal = \Gisl\Sdk\WorkflowConstants::TERMINAL_STATUSES;
        while (true) {
            if (self::nowMs() >= $deadlineMs) {
                throw new GislTimeoutError(
                    "Workflow {$workflowId} did not complete before maxWait deadline.",
                );
            }
            $status = $this->client->getWorkflowStatus($workflowId);
            $statusStr = self::coerceString($status->getStatus());
            if (\in_array($statusStr, $terminal, true)) {
                return $status;
            }
            // Codex TS r2 — re-check after the network round-trip so a
            // slow getWorkflowStatus call cannot push us past the deadline
            // before the pre-fetch check would have fired.
            if (self::nowMs() >= $deadlineMs) {
                throw new GislTimeoutError(
                    "Workflow {$workflowId} did not complete before maxWait deadline.",
                );
            }
            // If the next interval would push us past the deadline, fail
            // now rather than sleep-then-throw.
            if (self::nowMs() + $intervalMs >= $deadlineMs) {
                throw new GislTimeoutError(
                    "Workflow {$workflowId} did not complete before maxWait deadline.",
                );
            }
            // intervalMs is clamped to >= 100 above, so the sleep always
            // happens; the explicit guard would have been redundant.
            \usleep($intervalMs * 1_000);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function projectProcessingProgress(array $data): ProcessingProgressEvent
    {
        // SSE payload keys arrive in snake_case (per GislSseEvent docblock).
        // Convert to the typed projection without leaking generated
        // helpers — the wire keys are stable and small.
        return new ProcessingProgressEvent(
            progress: (float) ($data['progress'] ?? 0.0),
            jobRef: (string) ($data['job_ref'] ?? $data['jobRef'] ?? ''),
            operationId: (string) ($data['operation_id'] ?? $data['operationId'] ?? ''),
            status: isset($data['status']) ? (string) $data['status'] : null,
            stage: isset($data['stage']) ? (string) $data['stage'] : null,
            phaseInputIndex: isset($data['phase_input_index']) ? (int) $data['phase_input_index']
                : (isset($data['phaseInputIndex']) ? (int) $data['phaseInputIndex'] : null),
            phaseTotalInputs: isset($data['phase_total_inputs']) ? (int) $data['phase_total_inputs']
                : (isset($data['phaseTotalInputs']) ? (int) $data['phaseTotalInputs'] : null),
        );
    }

    private static function callableOrNull(mixed $value, string $label): ?\Closure
    {
        if ($value === null) {
            return null;
        }
        if (!\is_callable($value)) {
            throw new \InvalidArgumentException("{$label} must be callable when set.");
        }
        return \Closure::fromCallable($value);
    }

    /**
     * Wall-clock milliseconds since the Unix epoch. PHP's `microtime(true)`
     * returns seconds as float; multiply + cast for ms-int precision.
     */
    private static function nowMs(): int
    {
        return (int) (\microtime(true) * 1_000);
    }

    /**
     * Coerce a generated-model getter return value to a non-null string.
     * The openapi-generator declares enum-class getters as returning e.g.
     * `\…\OperationType` (an object type) but at runtime they yield raw
     * strings (the const values). PHPStan rejects `(string) $x` on those
     * declared object types; this helper bridges via `is_string` + cast.
     */
    private static function coerceString(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }
        if ($value === null) {
            return '';
        }
        // Fall back to a best-effort cast for ints/floats/bools coming
        // through schema-loosely-typed wire shapes. Objects/arrays
        // shouldn't reach here in practice; settle on empty string to
        // avoid throwing during result projection.
        return \is_scalar($value) ? (string) $value : '';
    }

    private static function coerceNullableString(mixed $value): ?string
    {
        return $value === null ? null : self::coerceString($value);
    }

    /**
     * Format a generated-model timestamp as UTC ISO-8601 with millisecond
     * precision and `Z` suffix (e.g. `2026-05-27T11:00:00.123Z`). Mirrors
     * `Date.toISOString()` in the TS reference at
     * `packages/typescript/src/builder.ts:769-773` — `RFC3339` would
     * silently diverge (`+00:00` suffix, no millis).
     */
    private static function formatIso8601Utc(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            $utc = $value->getTimezone()->getName() === 'UTC'
                ? $value
                : (new \DateTimeImmutable())
                    ->setTimestamp($value->getTimestamp())
                    ->setTimezone(new \DateTimeZone('UTC'));
            // 'v' = milliseconds (e.g. '123'); fall back to '000' on
            // DateTimeInterface implementations that lack microsecond
            // precision (rare; defensive).
            return $utc->format('Y-m-d\TH:i:s.v\Z');
        }
        return self::coerceNullableString($value);
    }
}
