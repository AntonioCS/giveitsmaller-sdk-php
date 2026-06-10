<?php

declare(strict_types=1);

namespace Gisl\Sdk;

use Gisl\Generated\OpenApi\Model\MultipartInitiateRequestMetadataHint;
use Gisl\Sdk\Errors\GislConfigError;

/**
 * Per-call options for {@see GislClient::uploadFile()}.
 *
 * Mirrors the TS `UploadOptions` interface at packages/typescript/src/types.ts:392-410.
 * `signal` is intentionally absent here — PHP's PSR-18 / PSR-7 surface has no
 * `AbortSignal` analogue. Cooperative cancellation lives at the ergonomic
 * layer ({@see \Gisl\Sdk\Cancellation} via `RunOptions`/`SubmitOptions`,
 * VOxtu0RZ-B3), which checks between steps; an in-flight transfer is not
 * interrupted mid-request (transfer-level abort is a possible follow-up).
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
     * @param string|null $contentType
     *   Caller-overridable Content-Type for the multipart `file` part. When
     *   null the SDK sends `application/octet-stream` (RFC 7578 §4.4
     *   unknown-binary fallback). Applies to both the single-shot upload and
     *   the multipart `/initiate` first-chunk part.
     */
    public function __construct(
        public mixed $onProgress = null,
        public ?MultipartInitiateRequestMetadataHint $metadataHint = null,
        public ?string $resumeUploadId = null,
        public mixed $onCheckpoint = null,
        public ?string $contentType = null,
    ) {
        // The content type is concatenated verbatim into the raw multipart
        // `Content-Type` header bytes in GislClient's body builders. Reject CR,
        // LF, or NUL here — the single chokepoint for the value — to prevent
        // header/body injection into the multipart wire form. Mirrors the
        // filename guard in buildSingleShotMultipartBody/buildMultipartInitiateBody.
        if ($contentType !== null && \preg_match('/[\r\n\x00]/', $contentType) === 1) {
            throw new GislConfigError(
                'UploadOptions contentType contains illegal characters for a multipart '
                . 'Content-Type header (no CR, LF, or NUL allowed): '
                . \var_export($contentType, true),
            );
        }
    }
}
