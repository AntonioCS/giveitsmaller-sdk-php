<?php

declare(strict_types=1);

namespace Gisl\Sdk\Http;

use Gisl\Generated\OpenApi\Model\PresignedUrlPart;

/**
 * Strategy seam for uploading the chunk PUTs of a multipart upload (P6+
 * `z9bDW2iH`). The default {@see GislClient} path is a sequential PSR-18 loop;
 * this seam lets the chunk PUTs run with bounded concurrency (e.g. via
 * `curl_multi`) WITHOUT making `GislClient` depend on a specific transport and
 * WITHOUT bypassing the injected PSR-18 client for the rest of the flow
 * (initiate / complete / status still go through the normal client).
 *
 * Injected into `GislClient` (defaulted) so it is overridable in tests — the
 * `StubPsr18Client`-based parity/unit suites keep exercising the sequential
 * PSR-18 floor by NOT providing a concurrent uploader.
 *
 * Implementations MUST preserve the sequential path's behaviour:
 *  - per-part bounded retry with full-jitter backoff,
 *  - retry-exhaustion / read-failure → {@see \Gisl\Sdk\Errors\GislMultipartPartError}
 *    (carrying partNumber + uploadId); non-retryable S3 HTTP / missing-ETag →
 *    {@see \Gisl\Sdk\Errors\GislError},
 *  - lazy byte reads (offset/length into the file — never buffer all parts),
 *  - `$onPartComplete($partNumber, $bytes)` fired after EACH successful part
 *    (drives progress + resume/checkpoint bookkeeping),
 *  - on the first fatal part, cancel in-flight PUTs and throw.
 *
 * Returns a `partNumber => etag` map; the caller assembles the ordered
 * `/multipart/complete` part list (parts may complete out of order under
 * concurrency).
 */
interface MultipartPartUploader
{
    /**
     * Upload the given parts (bounded by `$concurrency`) and return their ETags.
     *
     * @param list<array{presigned: PresignedUrlPart, offset: int, length: int}> $parts
     *        Lazy descriptors — `offset`/`length` index into `$filePath`; chunk
     *        bytes are read on demand, never pre-materialised.
     * @param callable(int $partNumber, int $bytes): void $onPartComplete
     *
     * @return array<int, string> partNumber => ETag
     */
    public function uploadParts(
        string $filePath,
        array $parts,
        string $uploadId,
        int $concurrency,
        callable $onPartComplete,
    ): array;
}
