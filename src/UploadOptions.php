<?php

declare(strict_types=1);

namespace Gisl\Sdk;

use Gisl\Generated\OpenApi\Model\MultipartInitiateRequestMetadataHint;

/**
 * Per-call options for {@see GislClient::uploadFile()}.
 *
 * Mirrors the TS `UploadOptions` interface at packages/typescript/src/types.ts:392-410.
 * `signal` is intentionally absent in this scaffold — PHP's PSR-18 / PSR-7
 * surface has no `AbortSignal` analogue, and the upload flow lands in
 * VOxtu0RZ-B2 which will introduce a cancellation primitive (likely a
 * `CancellationToken` interface compatible with PSR-related drafts). Adding
 * a stub now would commit us to a shape the cancellation work might want
 * to revise.
 */
final class UploadOptions
{
    /**
     * @param (callable(int $uploadedBytes, int $totalBytes): void)|null $onProgress
     *   Fired after each chunk completes during multipart uploads, and once
     *   at end of single-shot uploads. The callback receives the running
     *   uploaded byte count and total file size; both monotonically non-
     *   decreasing per call.
     * @param string|null $resumeUploadId
     *   SDK-3 (Wb6ebOMM): resume an in-progress multipart upload. When set,
     *   `uploadFile()` skips `/multipart/initiate` and instead walks
     *   `/status` -> presigns missing parts -> PUTs missing -> `/complete`.
     *   The caller's `$filePathOrResource` MUST be byte-identical to the
     *   file used in the original initiate call; mismatched bytes will
     *   produce S3 etags that don't match server state and `/complete`
     *   will reject.
     *
     *   Anonymous-initiated sessions cannot be resumed by an authed caller
     *   (server returns 403 -> `GislMultipartSessionAuthRequiredError`).
     *   Non-existent / expired sessions return 404 ->
     *   `GislMultipartSessionNotFoundError`. Authed-but-non-owning callers
     *   return 403 -> `GislMultipartSessionOwnershipError`.
     * @param (callable(MultipartCheckpointState $state): void)|null $onCheckpoint
     *   SDK-3 (Wb6ebOMM): called after every successful part PUT in
     *   fresh-upload AND resume paths. Receives a JSON-serialisable
     *   snapshot of upload state — persistable via `json_encode($state)`
     *   for round-trip across process restarts, so a future
     *   `uploadFile(filePath, new UploadOptions(resumeUploadId: $state->uploadId))`
     *   can pick up where the prior process stopped.
     *
     *   Callback fires OUTSIDE the per-part retry-scoped path: a throw here
     *   will fail the upload but NEVER trigger a duplicate PUT (mirrors the
     *   `onProgress` discipline).
     */
    public function __construct(
        public mixed $onProgress = null,
        public ?MultipartInitiateRequestMetadataHint $metadataHint = null,
        public ?string $resumeUploadId = null,
        public mixed $onCheckpoint = null,
    ) {
    }
}
