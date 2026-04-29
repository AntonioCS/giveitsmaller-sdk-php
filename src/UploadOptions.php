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
     */
    public function __construct(
        public mixed $onProgress = null,
        public ?MultipartInitiateRequestMetadataHint $metadataHint = null,
    ) {
    }
}
