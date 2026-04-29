<?php

declare(strict_types=1);

namespace Gisl\Sdk;

use Gisl\Generated\OpenApi\Model\AuthErrorResponse;
use Gisl\Generated\OpenApi\Model\AuthErrorType;
use Gisl\Generated\OpenApi\Model\BalanceExhaustedResponse;
use Gisl\Generated\OpenApi\Model\FeatureNotAvailableResponse;
use Gisl\Generated\OpenApi\Model\FeatureTierRestrictedResponse;
use Gisl\Generated\OpenApi\Model\MetadataResponse;
use Gisl\Generated\OpenApi\Model\MultipartInitiateResponse;
use Gisl\Generated\OpenApi\Model\PresignedUrlPart;
use Gisl\Generated\OpenApi\Model\RetryResponse;
use Gisl\Generated\OpenApi\Model\TierRestrictionResponse;
use Gisl\Generated\OpenApi\Model\UploadResponse;
use Gisl\Generated\OpenApi\Model\ValidationErrorEnvelope;
use Gisl\Generated\OpenApi\Model\WorkflowCancelResponse;
use Gisl\Generated\OpenApi\Model\WorkflowCreateResponse;
use Gisl\Generated\OpenApi\Model\WorkflowDownloadResponse;
use Gisl\Generated\OpenApi\Model\WorkflowExpiredResponse;
use Gisl\Generated\OpenApi\Model\WorkflowResumeResponse;
use Gisl\Generated\OpenApi\Model\WorkflowStatusResponse;
use Gisl\Generated\OpenApi\ObjectSerializer;
use Gisl\Sdk\Errors\GislApiError;
use Gisl\Sdk\Errors\GislAuthError;
use Gisl\Sdk\Errors\GislBalanceExhaustedError;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\Errors\GislError;
use Gisl\Sdk\Errors\GislFeatureNotAvailableError;
use Gisl\Sdk\Errors\GislFeatureTierRestrictedError;
use Gisl\Sdk\Errors\GislNetworkError;
use Gisl\Sdk\Errors\GislTierRestrictedError;
use Gisl\Sdk\Errors\GislTimeoutError;
use Gisl\Sdk\Errors\GislValidationError;
use Gisl\Sdk\Errors\GislWorkflowExpiredError;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Thin, customer-facing PHP SDK for the GISL compression service.
 *
 * Public-API surface mirrors `packages/typescript/src/client.ts`. This
 * scaffold (sub-card `VOxtu0RZ-A`) ships the constructor, the request loop,
 * envelope unwrapping, error mapping, and three core methods:
 *
 *   - {@see uploadFile}            single-shot or multipart upload (POST /api/uploads)
 *   - {@see createWorkflow}        POST /api/workflows
 *   - {@see getWorkflowStatus}     GET /api/workflows/{id}/status
 *   - {@see getWorkflowDownloads}  GET /api/workflows/{id}/downloads
 *
 * Sub-card `VOxtu0RZ-B2.3` (`lT54YsPS`) extends the surface with the
 * workflow lifecycle methods: {@see cancelWorkflow}, {@see resumeWorkflow},
 * {@see retryOperation}, {@see waitForWorkflow}, and {@see getMetadata}.
 *
 * Multipart routing is sequential in v0.x: chunks are PUT to S3 one at a
 * time regardless of `GislClientConfig::$multipartConcurrency`. The config
 * field is recorded for forward compatibility — concurrent multipart uploads
 * (Guzzle Pool / Symfony HttpClient) arrive in a separate follow-up card
 * (`lv43MVSl`). SSE and the parity runner arrive in `VOxtu0RZ-B2`
 * (`bf68ju2r`).
 *
 * The HTTP transport is PSR-18 abstract — callers may inject their own
 * client / factories, or let php-http/discovery resolve installed
 * implementations at runtime. Tests must always inject explicit mocks so the
 * assertion surface stays deterministic.
 */
final class GislClient
{
    private readonly ClientInterface $httpClient;
    private readonly RequestFactoryInterface $requestFactory;
    private readonly StreamFactoryInterface $streamFactory;

    public function __construct(
        public readonly GislClientConfig $config,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->httpClient = $httpClient ?? Psr18ClientDiscovery::find();
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    /**
     * Upload a file. Routes to single-shot for files at-or-below
     * `multipartThresholdBytes`, else to the sequential multipart path.
     *
     * @param string|resource $filePathOrResource Filesystem path string. Stream
     *                                            resources are deferred to
     *                                            `bf68ju2r` (B2) — the chunked
     *                                            path needs random-access
     *                                            reads and the resource
     *                                            contract there is still
     *                                            being decided.
     */
    public function uploadFile(
        mixed $filePathOrResource,
        ?UploadOptions $options = null,
    ): UploadResponse {
        if (\is_resource($filePathOrResource)) {
            throw new GislConfigError(
                'Stream-resource uploadFile() is deferred to VOxtu0RZ-B2; pass a filesystem path for now.',
            );
        }
        if (!\is_string($filePathOrResource)) {
            throw new GislConfigError(
                'uploadFile expected a string filesystem path or a stream resource; got ' . \get_debug_type($filePathOrResource) . '.',
            );
        }

        $filePath = $filePathOrResource;
        if (!\is_file($filePath) || !\is_readable($filePath)) {
            throw new GislConfigError("File not found or not readable: {$filePath}");
        }

        $size = \filesize($filePath);
        if ($size === false) {
            throw new GislConfigError("Unable to stat file: {$filePath}");
        }

        $fileName = \basename($filePath);

        if ($size > $this->config->multipartThresholdBytes) {
            return $this->multipartUpload($filePath, $fileName, $size, $options);
        }

        return $this->singleShotUpload($filePath, $fileName, $size, $options);
    }

    private function singleShotUpload(
        string $filePath,
        string $fileName,
        int $size,
        ?UploadOptions $options,
    ): UploadResponse {
        $boundary = $this->generateMultipartBoundary();
        $body = $this->buildSingleShotMultipartBody(
            boundary: $boundary,
            filePath: $filePath,
            fileName: $fileName,
        );

        $request = $this->buildRequest(
            method: 'POST',
            path: '/api/uploads',
            body: $body,
            extraHeaders: ['Content-Type' => "multipart/form-data; boundary={$boundary}"],
        );

        /** @var array<string, mixed> $data */
        $data = $this->sendAndUnwrap($request);
        $response = $this->hydrate(UploadResponse::class, $data);

        // Match the multipart path's progress contract: fire once at end. The
        // single-shot wire has no chunked granularity to report, but firing
        // here lets callers wire `onProgress` once and have it work for both
        // routes.
        $this->fireProgress($options, $size, $size);

        return $response;
    }

    /**
     * Sequential multipart upload.
     *
     * Three phases:
     *   1. POST /api/uploads/multipart/initiate with first 8 MiB as form-data.
     *      Server returns `upload_id`, `presigned_urls` for parts 2..N, the
     *      recommended chunk size, and the first-chunk etag (which it tracks
     *      server-side; the SDK does NOT submit part 1 in the complete call).
     *   2. PUT each remaining chunk to its presigned S3 URL, in order, with
     *      bounded retry + full-jitter backoff per part. `multipartConcurrency`
     *      is recorded but currently advisory — see `lv43MVSl` for the
     *      concurrent variant.
     *   3. POST /api/uploads/multipart/complete with the collected etags.
     *
     * Returns a synthesised {@see UploadResponse} so the public `uploadFile()`
     * surface is uniform across single-shot and multipart routes. The
     * multipart/complete wire response carries only `upload_id` + `status`;
     * the SDK pulls `mime_type` and `constraints_applied` from the initiate
     * response (the v2 contract makes `constraintsApplied` REQUIRED on
     * `UploadResponse` and the complete endpoint does not re-emit it).
     */
    private function multipartUpload(
        string $filePath,
        string $fileName,
        int $totalSize,
        ?UploadOptions $options,
    ): UploadResponse {
        $firstChunkSize = \min($totalSize, GislClientConfig::DEFAULT_MULTIPART_FIRST_CHUNK_SIZE_BYTES);

        $initiate = $this->multipartInitiate(
            filePath: $filePath,
            fileName: $fileName,
            totalSize: $totalSize,
            firstChunkSize: $firstChunkSize,
            metadataHint: $options?->metadataHint,
        );

        // Fail fast on a malformed initiate envelope — synthesising an
        // UploadResponse needs `constraints_applied` (the v2 contract makes
        // it REQUIRED on UploadResponse, and the multipart/complete endpoint
        // does NOT re-emit it). Catching this BEFORE the chunk uploads avoids
        // doing N PUTs + a complete call only to throw at synthesis time.
        $constraintsApplied = $initiate->getConstraintsApplied();
        if ($constraintsApplied === null) {
            throw new GislError(
                'Multipart initiate response missing constraints_applied; cannot synthesise UploadResponse.',
            );
        }

        $uploadedBytes = $firstChunkSize;
        $this->fireProgress($options, $uploadedBytes, $totalSize);

        $presignedUrls = $initiate->getPresignedUrls() ?? [];
        $chunkSize = $initiate->getRecommendedChunkSize();
        if ($chunkSize === null || $chunkSize < 1) {
            throw new GislError(
                "Multipart initiate response missing recommendedChunkSize; cannot route remaining bytes.",
            );
        }

        /** @var list<array{part_number: int, etag: string}> $parts */
        $parts = [];

        foreach ($presignedUrls as $index => $part) {
            if (!$part instanceof PresignedUrlPart) {
                throw new GislError("Multipart initiate response had a malformed presigned URL at index {$index}.");
            }
            $start = $firstChunkSize + $index * $chunkSize;
            $end = \min($start + $chunkSize, $totalSize);
            $contentLength = $end - $start;

            $etag = $this->putChunkWithRetry(
                presigned: $part,
                filePath: $filePath,
                offset: $start,
                length: $contentLength,
            );

            $partNumber = $part->getPartNumber();
            if ($partNumber === null) {
                throw new GislError("Presigned URL at index {$index} missing part_number.");
            }
            $parts[] = ['part_number' => $partNumber, 'etag' => $etag];

            $uploadedBytes += $contentLength;
            $this->fireProgress($options, $uploadedBytes, $totalSize);
        }

        // Wire shape the server expects on /multipart/complete. Iteration
        // order over `$presignedUrls` is part_number ascending per the v2
        // contract; we don't re-sort here so a wire shape that drifts from
        // that contract surfaces in fixture snapshots rather than getting
        // hidden by a defensive sort.
        $uploadId = $initiate->getUploadId();
        if (!\is_string($uploadId) || $uploadId === '') {
            throw new GislError('Multipart initiate response missing upload_id.');
        }

        $completeBody = $this->jsonEncode([
            'upload_id' => $uploadId,
            'parts' => $parts,
        ]);
        $completeRequest = $this->buildRequest(
            method: 'POST',
            path: '/api/uploads/multipart/complete',
            body: $this->streamFactory->createStream($completeBody),
            extraHeaders: ['Content-Type' => 'application/json'],
        );

        /** @var array<string, mixed> $completeData */
        $completeData = $this->sendAndUnwrap($completeRequest);
        $completeStatus = $completeData['status'] ?? null;
        if ($completeStatus !== 'completed') {
            throw new GislError(
                'Multipart upload completed with unexpected status: '
                . (\is_string($completeStatus) ? $completeStatus : \get_debug_type($completeStatus)),
            );
        }

        // The complete response carries the authoritative upload_id (matches
        // TS reference: idempotency replays / server-side rebinds end up
        // here, not on the initiate envelope).
        $completeUploadId = $completeData['upload_id'] ?? null;
        if (!\is_string($completeUploadId) || $completeUploadId === '') {
            throw new GislError('Multipart complete response missing upload_id.');
        }

        // Synthesise an UploadResponse from the initiate + complete envelopes.
        // The complete response only carries upload_id+status, so mime_type
        // and constraints_applied are pulled from initiate (the first-chunk
        // probe is the authoritative source for those fields per the v2
        // contract — multipart/complete intentionally does NOT re-emit them).
        // `constraintsApplied` is non-null here: the early check above fails
        // fast before any chunk uploads.
        return $this->hydrate(UploadResponse::class, [
            'file_id' => $completeUploadId,
            'original_name' => $fileName,
            'mime_type' => $initiate->getMimeType(),
            'size_bytes' => $totalSize,
            'constraints_applied' => ObjectSerializer::sanitizeForSerialization($constraintsApplied),
        ]);
    }

    private function multipartInitiate(
        string $filePath,
        string $fileName,
        int $totalSize,
        int $firstChunkSize,
        ?\Gisl\Generated\OpenApi\Model\MultipartInitiateRequestMetadataHint $metadataHint,
    ): MultipartInitiateResponse {
        $boundary = $this->generateMultipartBoundary();
        $body = $this->buildMultipartInitiateBody(
            boundary: $boundary,
            filePath: $filePath,
            fileName: $fileName,
            firstChunkSize: $firstChunkSize,
            totalSize: $totalSize,
            metadataHint: $metadataHint,
        );

        $request = $this->buildRequest(
            method: 'POST',
            path: '/api/uploads/multipart/initiate',
            body: $body,
            extraHeaders: ['Content-Type' => "multipart/form-data; boundary={$boundary}"],
        );

        /** @var array<string, mixed> $data */
        $data = $this->sendAndUnwrap($request);
        return $this->hydrate(MultipartInitiateResponse::class, $data);
    }

    /**
     * PUT a chunk to S3 with bounded retry and full-jitter backoff.
     *
     * Returns the ETag header value on success; throws on terminal failure or
     * after `multipartMaxAttempts` retryable failures. Mirrors the TS
     * `attemptPut` + per-part retry loop, minus the cross-worker abort
     * controller (sequential workers don't have peers to wake).
     *
     * The chunk bytes are read from disk ONCE before the retry loop and held
     * in memory across attempts. The TS reference holds an immutable
     * `Blob.slice()` view across retries; the PHP equivalent is reading the
     * bytes once and rewrapping in a fresh PSR-7 stream per attempt. Costs
     * one chunk-sized buffer (~5-50 MiB) but avoids re-reading from disk on
     * every transient S3 failure.
     */
    private function putChunkWithRetry(
        PresignedUrlPart $presigned,
        string $filePath,
        int $offset,
        int $length,
    ): string {
        $url = $presigned->getUrl();
        if (!\is_string($url) || $url === '') {
            throw new GislError("Presigned URL part {$presigned->getPartNumber()} has empty url.");
        }

        $partNumber = $presigned->getPartNumber() ?? 0;
        $chunkBytes = $this->readChunk($filePath, $offset, $length);

        $lastError = null;
        for ($attempt = 0; $attempt < $this->config->multipartMaxAttempts; $attempt++) {
            $request = $this->requestFactory
                ->createRequest('PUT', $url)
                ->withHeader('Content-Length', (string) $length)
                ->withBody($this->streamFactory->createStream($chunkBytes));

            try {
                $response = $this->httpClient->sendRequest($request);
            } catch (ClientExceptionInterface $e) {
                // Treat transport failures as retryable — the TS reference
                // distinguishes ECONNRESET / ECONNREFUSED / ETIMEDOUT here,
                // but PSR-18 collapses everything into ClientExceptionInterface
                // with no portable subtype. Bounded retry already caps the
                // damage from a permanent transport failure.
                $lastError = $e;
                $this->backoff($attempt);
                continue;
            }

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 200 && $statusCode < 300) {
                $etag = $response->getHeaderLine('ETag');
                if ($etag === '') {
                    // Drain the body before throwing — keeps the transport
                    // connection released eagerly, matching the TS reference's
                    // explicit drain on this same fatal path.
                    (string) $response->getBody();
                    throw new GislError(
                        "S3 response missing ETag for part {$partNumber}.",
                    );
                }
                return $etag;
            }

            // Non-2xx — drain the body so the connection can be released by
            // the underlying transport, then decide retry vs fatal.
            (string) $response->getBody();

            if (!$this->isRetryableStatus($statusCode)) {
                throw new GislError(
                    "S3 chunk upload failed for part {$partNumber}: HTTP {$statusCode} (non-retryable).",
                );
            }

            $lastError = new GislError(
                "S3 chunk upload failed for part {$partNumber}: HTTP {$statusCode}.",
            );
            $this->backoff($attempt);
        }

        $detail = $lastError instanceof \Throwable ? $lastError->getMessage() : 'unknown error';
        throw new GislError(
            "S3 chunk upload failed for part {$partNumber} after {$this->config->multipartMaxAttempts} attempts: {$detail}",
        );
    }

    private function isRetryableStatus(int $status): bool
    {
        // 408 Request Timeout, 429 Too Many Requests, 500-599 server errors.
        return $status === 408 || $status === 429 || ($status >= 500 && $status < 600);
    }

    /**
     * Cap on the per-retry sleep window. 30 s matches what most well-behaved
     * SDKs (AWS SDK, etc.) use as an upper bound — beyond this, repeated
     * retries are no longer "back off and retry" but "block the worker for
     * minutes," which is rarely what anyone wants. Also defends against
     * pathological config (high attempts × high base) producing minutes-long
     * sleeps OR int-overflow on `1 << attempt` for absurd attempt counts.
     */
    private const BACKOFF_CAP_MS = 30_000;

    private function backoff(int $attempt): void
    {
        if ($attempt + 1 >= $this->config->multipartMaxAttempts) {
            return;
        }
        $base = $this->config->multipartRetryBaseMs;
        if ($base <= 0) {
            return;
        }
        // Full-jitter: random_int(0, min(base * 2^attempt, cap)). Mirrors
        // fullJitterDelay at packages/typescript/src/client.ts but with an
        // explicit ceiling.  `1 << $attempt` is bounded by clamping the
        // exponent so we never produce a negative cap on 32-bit PHP nor a
        // multi-minute sleep on 64-bit.
        $shift = \max(0, \min($attempt, 30));
        $cap = \max(1, \min($base * (1 << $shift), self::BACKOFF_CAP_MS));
        $delayMs = \random_int(0, $cap);
        \usleep($delayMs * 1000);
    }

    private function readChunk(string $filePath, int $offset, int $length): string
    {
        if ($length < 1) {
            // fread requires length >= 1; a zero-byte chunk is never produced
            // by the multipart router (initiate enforces a non-zero first
            // chunk; subsequent chunk_size is recommendedChunkSize >= 1) but
            // guarding here keeps PHPStan honest and surfaces wire bugs early.
            throw new GislError("readChunk called with non-positive length ({$length}) at offset {$offset}.");
        }
        $fh = @\fopen($filePath, 'rb');
        if ($fh === false) {
            throw new GislError("Unable to reopen {$filePath} for chunk read.");
        }
        try {
            if (\fseek($fh, $offset) !== 0) {
                throw new GislError("Failed to seek to offset {$offset} in {$filePath}.");
            }
            $bytes = \fread($fh, $length);
            if ($bytes === false || \strlen($bytes) !== $length) {
                $got = $bytes === false ? 'false' : (string) \strlen($bytes);
                throw new GislError(
                    "Short read at offset {$offset} in {$filePath}: expected {$length} bytes, got {$got}.",
                );
            }
            return $bytes;
        } finally {
            \fclose($fh);
        }
    }

    private function fireProgress(?UploadOptions $options, int $uploaded, int $total): void
    {
        $cb = $options?->onProgress;
        if ($cb === null) {
            return;
        }
        if (!\is_callable($cb)) {
            throw new GislConfigError(
                'UploadOptions::$onProgress must be callable when set.',
            );
        }
        $cb($uploaded, $total);
    }

    public function createWorkflow(WorkflowCreatePayload $payload): WorkflowCreateResponse
    {
        $jsonBody = $this->jsonEncode($payload->toWire());
        $request = $this->buildRequest(
            method: 'POST',
            path: '/api/workflows',
            body: $this->streamFactory->createStream($jsonBody),
            extraHeaders: ['Content-Type' => 'application/json'],
        );

        /** @var array<string, mixed> $data */
        $data = $this->sendAndUnwrap($request);
        return $this->hydrate(WorkflowCreateResponse::class, $data);
    }

    public function getWorkflowStatus(string $workflowId): WorkflowStatusResponse
    {
        // rawurlencode the path segment — workflow IDs come from the server
        // as ULID/UUID-shaped strings, but the SDK can't assume that, and a
        // workflowId containing `/`, `?`, `#`, or unicode would otherwise
        // alter the requested route silently.
        $encoded = \rawurlencode($workflowId);
        $request = $this->buildRequest(
            method: 'GET',
            path: "/api/workflows/{$encoded}/status",
        );

        /** @var array<string, mixed> $data */
        $data = $this->sendAndUnwrap($request);
        return $this->hydrate(WorkflowStatusResponse::class, $data);
    }

    /**
     * Fetch download URLs for a completed workflow. Mirrors TS
     * `getWorkflowDownloads(workflowId)` at packages/typescript/src/client.ts.
     *
     * The response carries one or more `JobDownload` entries — each job in
     * the workflow that produced output exposes its files (and optionally a
     * combined zip URL). Helper streaming-to-disk is left to the caller; PSR-7
     * `ResponseInterface::getBody()` already returns a streaming body, and
     * adding a `downloadResultTo($path)` helper overlaps too much with
     * existing PSR-7 idioms — readers can call `copy('php://memory', $stream)`
     * or write their own loop.
     */
    public function getWorkflowDownloads(string $workflowId): WorkflowDownloadResponse
    {
        $encoded = \rawurlencode($workflowId);
        $request = $this->buildRequest(
            method: 'GET',
            path: "/api/workflows/{$encoded}/downloads",
        );

        /** @var array<string, mixed> $data */
        $data = $this->sendAndUnwrap($request);
        return $this->hydrate(WorkflowDownloadResponse::class, $data);
    }

    /**
     * Stream Server-Sent Events for a workflow, yielding one
     * {@see GislSseEvent} per parsed frame.
     *
     * Mirrors `streamEvents()` at `packages/typescript/src/client.ts:1104`
     * and the SSE parser at `packages/typescript/src/sse.ts`. Wire details:
     *
     *   - `GET /api/workflows/{rawurlencode($workflowId)}/events`
     *   - The connection is held open by the server; the generator
     *     yields events as they arrive. The connection closes when the
     *     server ends the stream OR the caller `break`s out of the
     *     foreach (PHP destructs the generator and releases the body).
     *   - Comment lines (`:` prefix) are skipped — keep-alives.
     *   - `id:` and `retry:` are explicitly IGNORED (NOT surfaced on
     *     {@see GislSseEvent}). The SDK does not implement
     *     Last-Event-ID reconnection nor honour server-suggested retry
     *     intervals.
     *   - Each frame's `data:` body is JSON-decoded as an associative
     *     array. Frames whose body fails to parse are SKIPPED — the
     *     stream does NOT throw mid-flight on a single garbled frame,
     *     because long-running consumers should stay up across
     *     transient server hiccups. (TS reference falls back to a
     *     plain string; PHP rejects entirely so the typed `data`
     *     contract holds.)
     *
     * Non-2xx responses are routed through {@see unwrapEnvelope} so
     * the typed-error dispatch (auth, balance, validation, …) shipped
     * in B2.1 surfaces the same exceptions for SSE as for JSON
     * endpoints.
     *
     * @return \Generator<int, GislSseEvent, void, void>
     * @throws GislNetworkError on transport failure.
     * @throws GislApiError     on a non-2xx envelope (typed subclass
     *                          where the dispatch matches).
     */
    public function streamEvents(string $workflowId): \Generator
    {
        $encoded = \rawurlencode($workflowId);
        $request = $this->buildRequest(
            method: 'GET',
            path: "/api/workflows/{$encoded}/events",
        );

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new GislNetworkError(
                "HTTP transport failed: {$e->getMessage()}",
                $e,
            );
        }

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            // Route through the JSON envelope dispatcher — let the
            // typed-error tree (GislAuthError, GislBalanceExhaustedError,
            // …) throw. unwrapEnvelope always throws on non-success here:
            // a non-2xx with a `success: true` envelope is malformed and
            // the early-empty-body path also throws. Either way we never
            // return a plain "data" payload for a non-2xx wire response.
            $this->unwrapEnvelope($response);
            // Defense-in-depth — should be unreachable.
            throw new GislError(
                "SSE stream returned status {$statusCode} with a success envelope.",
            );
        }

        return $this->parseSseStream($response->getBody());
    }

    /**
     * Generator-internal SSE parser. Reads the PSR-7 body in chunks,
     * splits on \n / \r\n / \r per the SSE spec, accumulates `data:`
     * lines into one frame, joins multiple `data:` lines with `\n`,
     * JSON-decodes on blank-line boundaries, and yields a
     * {@see GislSseEvent} per successfully-parsed frame.
     *
     * @return \Generator<int, GislSseEvent, void, void>
     */
    private function parseSseStream(\Psr\Http\Message\StreamInterface $body): \Generator
    {
        $buffer = '';
        $eventType = '';
        /** @var list<string> $dataLines */
        $dataLines = [];

        while (!$body->eof()) {
            $chunk = $body->read(self::SSE_READ_CHUNK_BYTES);
            if ($chunk === '') {
                // Some PSR-7 streams return '' before EOF on slow
                // network reads. Don't busy-loop: if we're not at EOF,
                // the next read() attempt should block until bytes
                // arrive. If we ARE at EOF the while() exits cleanly.
                continue;
            }
            $buffer .= $chunk;

            // SSE spec: lines terminated by \n, \r\n, or \r. Normalise
            // so a single split produces canonical lines.
            $buffer = \str_replace(["\r\n", "\r"], "\n", $buffer);
            $lines = \explode("\n", $buffer);
            // Last entry is potentially incomplete (no terminator yet);
            // hold it back into the buffer for the next read.
            $buffer = (string) \array_pop($lines);

            foreach ($lines as $line) {
                if ($line === '') {
                    // Blank line = frame terminator. Flush.
                    $event = $this->flushSseFrame($eventType, $dataLines);
                    $eventType = '';
                    $dataLines = [];
                    if ($event !== null) {
                        yield $event;
                    }
                    continue;
                }

                if ($line[0] === ':') {
                    // Comment / keep-alive. Skip.
                    continue;
                }

                $colonIndex = \strpos($line, ':');
                if ($colonIndex === false) {
                    // Field with no value — SSE spec treats the whole
                    // line as the field name with empty value. Only
                    // `event` / `data` matter to us; both are useless
                    // empty, so drop the line.
                    continue;
                }

                $field = \substr($line, 0, $colonIndex);
                // Strip a single optional space after the colon per the SSE spec.
                $valueStart = ($colonIndex + 1 < \strlen($line) && $line[$colonIndex + 1] === ' ')
                    ? $colonIndex + 2
                    : $colonIndex + 1;
                $fieldValue = \substr($line, $valueStart);

                switch ($field) {
                    case 'event':
                        $eventType = $fieldValue;
                        break;
                    case 'data':
                        $dataLines[] = $fieldValue;
                        break;
                    case 'id':
                    case 'retry':
                        // Intentionally ignored — see GislSseEvent
                        // class docblock for rationale.
                        break;
                    default:
                        // Unknown field — drop per SSE spec.
                        break;
                }
            }
        }

        // Flush any final frame at stream end (server closed without a
        // trailing blank line).
        if ($dataLines !== []) {
            $event = $this->flushSseFrame($eventType, $dataLines);
            if ($event !== null) {
                yield $event;
            }
        }
    }

    /**
     * Read-chunk size for SSE. 8 KiB is large enough that typical
     * progress / status frames (<1 KiB) come back in one read, while
     * still being small enough that the buffer never grows unbounded
     * if the server keeps the connection open for hours.
     */
    private const SSE_READ_CHUNK_BYTES = 8192;

    /**
     * Build one {@see GislSseEvent} from the accumulated frame state
     * or return null if the frame should be dropped (no data lines, or
     * malformed JSON).
     *
     * @param list<string> $dataLines
     */
    private function flushSseFrame(string $eventType, array $dataLines): ?GislSseEvent
    {
        if ($dataLines === []) {
            return null;
        }
        $rawData = \implode("\n", $dataLines);
        try {
            /** @var mixed $decoded */
            $decoded = \json_decode($rawData, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // Skip malformed frames. Long-running consumers must not
            // break on a single garbled payload — server-side glitches
            // / partial flushes happen in the wild.
            if (\defined('PHP_DEBUG') && PHP_DEBUG) {
                \error_log(
                    "GISL SDK: dropping SSE frame with non-JSON data: {$e->getMessage()}",
                );
            }
            return null;
        }

        // Per SSE spec: an event with no `event:` field defaults to
        // "message". Mirrors the TS reference at sse.ts:50.
        $name = $eventType !== '' ? $eventType : 'message';
        return new GislSseEvent(event: $name, data: $decoded);
    }

    /**
     * Cancel a workflow. Idempotent — cancelling an already-cancelled
     * workflow returns 200 with the same shape (and the original
     * `cancelledAt`). Cancelling a `completed` / `failed` /
     * `partially_failed` / `expired` workflow returns 409, which the SDK
     * surfaces as a generic {@see GislApiError} (NOT a special class) so
     * callers can branch on `$e->statusCode === 409` if needed.
     *
     * The response's `billingEffect` field tells the caller what happened to
     * outstanding reservations:
     *  - `unspent_reservation_released` — the unspent portion of the
     *    reservation has been refunded as a separate `CreditTransaction`
     *    with `type: refund`.
     *  - `none` — no refund (all reserved credits were already consumed by
     *    completed jobs, or this is an idempotent re-cancel).
     *
     * Mirrors `packages/typescript/src/client.ts::cancelWorkflow`.
     */
    public function cancelWorkflow(string $workflowId): WorkflowCancelResponse
    {
        $encoded = \rawurlencode($workflowId);
        $request = $this->buildRequest(
            method: 'POST',
            path: "/api/workflows/{$encoded}/cancel",
        );

        /** @var array<string, mixed> $data */
        $data = $this->sendAndUnwrap($request);
        return $this->hydrate(WorkflowCancelResponse::class, $data);
    }

    /**
     * Resume a workflow that is in `paused_insufficient_credits`.
     *
     * Resume succeeds only when `availableCredits` covers the next
     * reservation. If the balance is still insufficient, a
     * {@see GislBalanceExhaustedError} (402) surfaces and the workflow stays
     * paused. If the workflow is past its `expiresAt` (default 7-day TTL
     * from `pausedAt`), {@see GislWorkflowExpiredError} (422) surfaces and
     * the workflow has transitioned to `expired` — callers cannot un-expire
     * a workflow. Resuming a workflow that is not in
     * `paused_insufficient_credits` is a 409 (no-op) and surfaces as a
     * generic {@see GislApiError}.
     *
     * Mirrors `packages/typescript/src/client.ts::resumeWorkflow`.
     */
    public function resumeWorkflow(string $workflowId): WorkflowResumeResponse
    {
        $encoded = \rawurlencode($workflowId);
        $request = $this->buildRequest(
            method: 'POST',
            path: "/api/workflows/{$encoded}/resume",
        );

        /** @var array<string, mixed> $data */
        $data = $this->sendAndUnwrap($request);
        return $this->hydrate(WorkflowResumeResponse::class, $data);
    }

    /**
     * Retry a single failed operation. Note: keyed on **operationId**, not
     * workflowId — the contract pins this to `POST /api/operations/{id}/retry`.
     *
     * Returns the new operation's id (and the original id for traceability).
     * Returns 409 when the operation is not failed or has already been
     * retried; the SDK surfaces 409 as a generic {@see GislApiError} so
     * callers can decide whether to ignore or surface the conflict.
     *
     * Mirrors `packages/typescript/src/client.ts::retryOperation`.
     */
    public function retryOperation(string $operationId): RetryResponse
    {
        $encoded = \rawurlencode($operationId);
        $request = $this->buildRequest(
            method: 'POST',
            path: "/api/operations/{$encoded}/retry",
        );

        /** @var array<string, mixed> $data */
        $data = $this->sendAndUnwrap($request);
        return $this->hydrate(RetryResponse::class, $data);
    }

    /**
     * Get full metadata for an uploaded file. The shape varies by mime-type
     * family: image uploads carry `dimensions` + EXIF; documents carry
     * page-count fields; etc. The DTO exposes every nullable field — callers
     * read the ones relevant to their workflow.
     *
     * Mirrors `packages/typescript/src/client.ts::getMetadata`.
     */
    public function getMetadata(string $fileId): MetadataResponse
    {
        $encoded = \rawurlencode($fileId);
        $request = $this->buildRequest(
            method: 'GET',
            path: "/api/uploads/{$encoded}/metadata",
        );

        /** @var array<string, mixed> $data */
        $data = $this->sendAndUnwrap($request);
        return $this->hydrate(MetadataResponse::class, $data);
    }

    /**
     * Poll {@see getWorkflowStatus()} until the workflow reaches a terminal
     * status (completed / failed / partially_failed / cancelled / expired /
     * paused_insufficient_credits — see {@see WorkflowConstants::TERMINAL_STATUSES}).
     *
     * Pass a {@see WaitOptions} to override the default 2 s poll interval
     * and 5 min overall deadline, and to register an `onPoll` callback that
     * fires once per polling cycle (including the very first status fetch)
     * with the current status string.
     *
     * Throws {@see GislTimeoutError} when the next interval would push the
     * total elapsed past `timeoutMs`. The deadline is tracked in nanoseconds
     * via `hrtime(true)` for monotonic-clock semantics (robust to NTP
     * adjustments and DST shifts) and sub-ms precision (lets test cases
     * with `timeoutMs: 0` trip after any time has passed instead of
     * looping forever on integer-ms truncation).
     *
     * Mirrors `packages/typescript/src/client.ts::waitForWorkflow`. Note
     * that PHP's `usleep` is a real-time sleep — tests should pass
     * `intervalMs: 0` (and a small `timeoutMs`) to keep the suite fast.
     */
    public function waitForWorkflow(
        string $workflowId,
        ?WaitOptions $options = null,
    ): WorkflowStatusResponse {
        $intervalMs = ($options !== null ? $options->intervalMs : null) ?? WorkflowConstants::DEFAULT_POLL_INTERVAL_MS;
        $timeoutMs = ($options !== null ? $options->timeoutMs : null) ?? WorkflowConstants::DEFAULT_POLL_TIMEOUT_MS;
        $onPoll = $options?->onPoll;

        if ($onPoll !== null && !\is_callable($onPoll)) {
            throw new GislConfigError(
                'WaitOptions::$onPoll must be callable when set.',
            );
        }

        $intervalNs = $intervalMs * 1_000_000;
        $timeoutNs = $timeoutMs * 1_000_000;
        $deadlineNs = \hrtime(true) + $timeoutNs;

        while (true) {
            $status = $this->getWorkflowStatus($workflowId);
            $statusString = $status->getStatus() ?? '';

            if ($onPoll !== null) {
                $onPoll($statusString);
            }

            if (\in_array($statusString, WorkflowConstants::TERMINAL_STATUSES, true)) {
                return $status;
            }

            $nowNs = \hrtime(true);
            if ($nowNs + $intervalNs > $deadlineNs) {
                throw new GislTimeoutError(
                    "Workflow {$workflowId} did not complete within {$timeoutMs}ms",
                );
            }

            if ($intervalMs > 0) {
                \usleep($intervalMs * 1000);
            }
        }
    }

    // ---------------------------------------------------------------------
    // Internal helpers
    // ---------------------------------------------------------------------

    /**
     * @param array<string, string> $extraHeaders
     */
    private function buildRequest(
        string $method,
        string $path,
        mixed $body = null,
        array $extraHeaders = [],
    ): RequestInterface {
        $request = $this->requestFactory->createRequest(
            $method,
            $this->config->baseUrl . $path,
        );

        $request = $request
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', 'giveitsmaller-sdk-php/0.1.0');

        if ($this->config->apiKey !== null) {
            $request = $request->withHeader('Authorization', "Bearer {$this->config->apiKey}");
        }

        foreach ($this->config->headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        foreach ($extraHeaders as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            // PSR-7 stream OR string-able. The streamFactory path handles both.
            if (\is_string($body)) {
                $body = $this->streamFactory->createStream($body);
            }
            $request = $request->withBody($body);
        }

        return $request;
    }

    /**
     * Send the request via the PSR-18 client, normalise transport failures
     * into {@see GislNetworkError}, then unwrap the success envelope.
     *
     * @return mixed Decoded `data` field on `{ success: true, data: ... }`.
     *               Throws on `{ success: false, ... }` with a typed
     *               `GislApiError` (or `GislAuthError` for 401).
     * @throws GislNetworkError
     * @throws GislApiError
     * @throws GislError
     */
    private function sendAndUnwrap(RequestInterface $request): mixed
    {
        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new GislNetworkError(
                "HTTP transport failed: {$e->getMessage()}",
                $e,
            );
        }

        return $this->unwrapEnvelope($response);
    }

    /**
     * Strip the `{ success: bool, data | error, ... }` wire envelope.
     *
     * @return mixed The contents of `data` on success.
     * @throws GislApiError on `success: false`.
     * @throws GislError    when the envelope can't be parsed at all.
     */
    private function unwrapEnvelope(ResponseInterface $response): mixed
    {
        $statusCode = $response->getStatusCode();
        $rawBody = (string) $response->getBody();

        if ($rawBody === '') {
            // 204 No Content responses have no envelope. The SDK methods
            // landing in VOxtu0RZ-B2 (submitContact, etc.) return void in
            // that case; the three methods this scaffold exposes always
            // produce a body, so empty here is unexpected.
            throw new GislError(
                "Empty response body from {$response->getStatusCode()} (expected JSON envelope).",
            );
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = \json_decode($rawBody, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new GislError(
                "Server returned non-JSON body (status {$statusCode}): " . $e->getMessage(),
                0,
                $e,
            );
        }

        $success = $decoded['success'] ?? null;

        if ($success === true) {
            if (!\array_key_exists('data', $decoded)) {
                throw new GislError(
                    "Success envelope missing `data` field (status {$statusCode}).",
                );
            }
            return $decoded['data'];
        }

        // Failure envelope: { success: false, error, details?, message_key?, ... }
        $errorCode = isset($decoded['error']) && \is_string($decoded['error'])
            ? $decoded['error']
            : 'unknown_error';
        $message = isset($decoded['message']) && \is_string($decoded['message'])
            ? $decoded['message']
            : "Request failed with status {$statusCode} ({$errorCode}).";

        // Localisation triple per ticket I26 — surfaced on every typed error so
        // consumers can drive client-side i18n catalogs without unwrapping the
        // typed payload. Wire keys are snake_case; the typed-payload subclasses
        // also carry camelCase copies on the typed DTO. Mirrors
        // packages/typescript/src/client.ts:495-503.
        $messageKey = isset($decoded['message_key']) && \is_string($decoded['message_key'])
            ? $decoded['message_key']
            : null;
        $locale = isset($decoded['locale']) && \is_string($decoded['locale'])
            ? $decoded['locale']
            : null;
        /** @var array<string, mixed>|null $messageParams */
        $messageParams = isset($decoded['message_params']) && \is_array($decoded['message_params'])
            ? $decoded['message_params']
            : null;

        $errorType = isset($decoded['error_type']) && \is_string($decoded['error_type'])
            ? $decoded['error_type']
            : null;

        // Validation-envelope branch first — preserve the TS dispatch order so
        // a future caller matching on `instanceof GislValidationError` keeps
        // working. Detection is shape-based on `details`: each entry must be
        // an array with a `message` string. Envelopes whose `details` use a
        // different key (legacy `reason` from v1) fall through to the generic
        // GislApiError path so existing callers reading `$e->payload['details']`
        // keep working.
        $details = $decoded['details'] ?? null;
        if (\is_array($details) && $details !== [] && $this->isValidationDetails($details)) {
            $typedValidation = $this->tryDeserialize(ValidationErrorEnvelope::class, $decoded);
            if ($typedValidation instanceof ValidationErrorEnvelope) {
                throw new GislValidationError(
                    $message,
                    $statusCode,
                    $errorCode,
                    $typedValidation,
                    $decoded,
                    $messageKey,
                    $locale,
                    $messageParams,
                );
            }
        }

        // Dispatch by (statusCode, errorType) onto the structured envelope shapes
        // emitted by the v2 contracts. Each typed branch builds the typed DTO
        // via ObjectSerializer::deserialize; if construction throws OR the
        // resulting DTO fails the per-branch validity check, fall through to
        // the generic GislApiError rather than handing the caller a
        // half-constructed typed error. Mirrors
        // packages/typescript/src/client.ts:517-621.
        if (($statusCode === 401 || $statusCode === 403)
            && $errorType !== null
            && \in_array($errorType, AuthErrorType::getAllowableEnumValues(), true)
        ) {
            $typed = $this->tryDeserialize(AuthErrorResponse::class, $decoded);
            if ($typed instanceof AuthErrorResponse && \is_string($typed->getErrorType())) {
                throw new GislAuthError(
                    $message,
                    $statusCode,
                    $errorCode,
                    $decoded,
                    $messageKey,
                    $locale,
                    $messageParams,
                    $typed,
                );
            }
        }

        if ($statusCode === 402 && $errorType === 'balance_exhausted') {
            $typed = $this->tryDeserialize(BalanceExhaustedResponse::class, $decoded);
            if ($typed instanceof BalanceExhaustedResponse && \is_string($typed->getRequiredAction())) {
                throw new GislBalanceExhaustedError(
                    $message,
                    $statusCode,
                    $errorCode,
                    $typed,
                    $decoded,
                    $messageKey,
                    $locale,
                    $messageParams,
                );
            }
        }

        if ($statusCode === 403 && $errorType === 'tier_restriction') {
            $typed = $this->tryDeserialize(TierRestrictionResponse::class, $decoded);
            if ($typed instanceof TierRestrictionResponse
                && \is_string($typed->getRestrictionKind())
                && \is_string($typed->getCurrentTier())
            ) {
                throw new GislTierRestrictedError(
                    $message,
                    $statusCode,
                    $errorCode,
                    $typed,
                    $decoded,
                    $messageKey,
                    $locale,
                    $messageParams,
                );
            }
        }

        if ($statusCode === 403 && $errorType === 'feature_tier_restricted') {
            $typed = $this->tryDeserialize(FeatureTierRestrictedResponse::class, $decoded);
            if ($typed instanceof FeatureTierRestrictedResponse && $this->areFeatureViolations($typed->getViolations())) {
                throw new GislFeatureTierRestrictedError(
                    $message,
                    $statusCode,
                    $errorCode,
                    $typed,
                    $decoded,
                    $messageKey,
                    $locale,
                    $messageParams,
                );
            }
        }

        if ($statusCode === 422 && $errorType === 'feature_not_available') {
            $typed = $this->tryDeserialize(FeatureNotAvailableResponse::class, $decoded);
            if ($typed instanceof FeatureNotAvailableResponse && $this->areFeatureViolations($typed->getViolations())) {
                throw new GislFeatureNotAvailableError(
                    $message,
                    $statusCode,
                    $errorCode,
                    $typed,
                    $decoded,
                    $messageKey,
                    $locale,
                    $messageParams,
                );
            }
        }

        if ($statusCode === 422 && $errorType === 'workflow_expired') {
            $typed = $this->tryDeserialize(WorkflowExpiredResponse::class, $decoded);
            if ($typed instanceof WorkflowExpiredResponse && $typed->getExpiredAt() instanceof \DateTimeInterface) {
                throw new GislWorkflowExpiredError(
                    $message,
                    $statusCode,
                    $errorCode,
                    $typed,
                    $decoded,
                    $messageKey,
                    $locale,
                    $messageParams,
                );
            }
        }

        // Generic fallback.
        //
        // **PHP-only divergence from TS.** TS routes 401 with no recognised
        // `error_type` to base `GislApiError` (`packages/typescript/src/client.ts`
        // `tryThrowStructured` falls through, then line ~623 throws the base
        // class). PHP retains a 401-always-`GislAuthError` shortcut here so
        // legacy callers matching on `instanceof GislAuthError` for the
        // pre-typed `invalid_api_key` envelope (`GislClientTest::testFailureEnvelope401ThrowsGislAuthError`,
        // shipped in B1 scaffold) keep working. The typed payload is `null`
        // in this fallback path. 403 has no symmetric fallback — TS doesn't
        // either.
        if ($statusCode === 401) {
            throw new GislAuthError(
                $message,
                $statusCode,
                $errorCode,
                $decoded,
                $messageKey,
                $locale,
                $messageParams,
                null,
            );
        }

        throw new GislApiError(
            $message,
            $statusCode,
            $errorCode,
            $decoded,
            $messageKey,
            $locale,
            $messageParams,
        );
    }

    /**
     * Shape check for the `details` array of a validation envelope. Each
     * entry must be an associative array carrying at least a `message`
     * string — the only field marked required on
     * `ValidationErrorEnvelopeDetailsInner` in the v2 contract. `field`,
     * `operation`, `option`, plus the localisation triple are all optional.
     *
     * Envelopes whose `details` use a different key (e.g. legacy `reason`
     * from the v1 contract used in `testFailureEnvelope4xxThrowsGislApiErrorWithPayload`)
     * intentionally return false here so the dispatch falls through to the
     * generic {@see GislApiError} path.
     *
     * @param array<int|string, mixed> $details
     */
    private function isValidationDetails(array $details): bool
    {
        // Reject associative shapes — validation envelopes always emit a list.
        if (!\array_is_list($details)) {
            return false;
        }
        foreach ($details as $entry) {
            if (!\is_array($entry)) {
                return false;
            }
            if (!isset($entry['message']) || !\is_string($entry['message'])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Validity check for the `violations` array carried by
     * {@see FeatureTierRestrictedResponse} and {@see FeatureNotAvailableResponse}.
     * Both contracts require a non-empty list of {@see FeatureViolation}
     * with a string `feature` field — anything less means the typed DTO
     * deserialised but won't carry the contract-pinned shape, so we fall
     * through to the generic `GislApiError`.
     *
     * @param mixed $violations
     */
    private function areFeatureViolations(mixed $violations): bool
    {
        // Note on empty-array divergence from TS: TS dispatches to the typed
        // subclass even with `violations: []` (`[].every(...) === true`). PHP
        // cannot match — the generated DTO's `setViolations` throws on
        // `count < 1` (the contract pins `minItems: 1`), so `tryDeserialize`
        // returns null upstream and we never reach this check. Empty
        // violations is a malformed-envelope case anyway: the server
        // contract guarantees at least one violation when the envelope
        // exists, so falling through to base `GislApiError` is the correct
        // behaviour for that wire shape. Code-reviewer round 2 flagged the
        // divergence; this comment is the agreed resolution.
        if (!\is_array($violations) || $violations === []) {
            return false;
        }
        foreach ($violations as $violation) {
            if (!\is_object($violation)) {
                return false;
            }
            if (!\method_exists($violation, 'getFeature')) {
                return false;
            }
            if (!\is_string($violation->getFeature())) {
                return false;
            }
        }
        return true;
    }

    /**
     * Defense-in-depth wrapper around {@see ObjectSerializer::deserialize}.
     * Returns null on any throwable so callers can fall through to the
     * generic dispatch instead of throwing a half-constructed typed error.
     *
     * @template T of object
     * @param class-string<T>      $modelClass
     * @param array<string, mixed> $data
     * @return T|null
     */
    private function tryDeserialize(string $modelClass, array $data): ?object
    {
        // The generated typed-error DTOs (`BalanceExhaustedResponse`, etc.)
        // emit `getSuccessAllowableValues() === ['false']` — the openapi-
        // generator's representation of the contract's `const: false` literal
        // is a string-enum, NOT a bool. Real server envelopes carry the bool
        // `success: false`; ObjectSerializer::deserialize coerces it to PHP
        // bool, then the setter rejects it via `in_array(false, ['false'], true) === false`
        // and throws `InvalidArgumentException`. That bubbles up here and
        // causes EVERY typed-error branch to silently fall through to base
        // `GislApiError` — defeating the entire purpose of typed dispatch.
        //
        // Strip `success` before deserializing. The field is only a contract
        // marker; nothing downstream reads it from the typed DTO. Tracked as
        // contracts-generator card `09eNib6R` (string-enum should be a bool
        // literal const). Removal-trigger test in
        // `tests/Unit/GislClientTypedErrorsTest.php::testTypedDtoStillRejectsBoolSuccessWithoutWorkaround`
        // — when that test flips, this `unset` line can be removed.
        unset($data['success']);

        try {
            /** @var T $instance */
            $instance = ObjectSerializer::deserialize($data, $modelClass, []);
            return $instance;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Hydrate a generated DTO from the unwrapped `data` payload via the
     * generated `ObjectSerializer::deserialize`. This is recursive — nested
     * fields like `WorkflowStatusResponse::jobs` (`JobStatus[]`) and
     * `UploadResponse::constraints_applied` come back as their typed
     * objects, not raw arrays. Direct construction (`new $modelClass($data)`)
     * is shallow and would leave nested fields as untyped arrays — broken
     * for any caller using getter chains like `$result->getJobs()[0]->getJobId()`.
     *
     * @template T of object
     * @param class-string<T>      $modelClass
     * @param array<string, mixed> $data
     * @return T
     */
    private function hydrate(string $modelClass, array $data): object
    {
        /** @var T $instance */
        $instance = ObjectSerializer::deserialize($data, $modelClass, []);
        return $instance;
    }

    /**
     * @param array<mixed, mixed> $data
     */
    private function jsonEncode(array $data): string
    {
        try {
            return \json_encode($data, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new GislError("Failed to JSON-encode request body: " . $e->getMessage(), 0, $e);
        }
    }

    private function generateMultipartBoundary(): string
    {
        // 32 hex chars; collision-free in practice for upload boundaries.
        return '----GislSdkBoundary' . \bin2hex(\random_bytes(16));
    }

    private function buildSingleShotMultipartBody(
        string $boundary,
        string $filePath,
        string $fileName,
    ): \Psr\Http\Message\StreamInterface {
        // RFC 7578 §4.2 requires Content-Disposition `filename` values to be
        // quoted-string per RFC 2616. A filename containing `"`, CR, or LF
        // would either break the header or inject body content. Reject
        // loudly rather than silently sanitising — bad filenames are bugs in
        // the caller's data pipeline that should surface clearly.
        if (\preg_match('/["\r\n\x00]/', $fileName) === 1) {
            throw new GislConfigError(
                'Filename contains illegal characters for multipart Content-Disposition '
                . '(no `"`, CR, LF, or NUL allowed): ' . \var_export($fileName, true),
            );
        }

        $contents = \file_get_contents($filePath);
        if ($contents === false) {
            throw new GislConfigError("Unable to read file: {$filePath}");
        }

        // Single FormData field named "file" with filename. Mirrors the TS
        // singleUpload() at packages/typescript/src/client.ts:687-701.
        $crlf = "\r\n";
        $body = "--{$boundary}{$crlf}"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"{$fileName}\"{$crlf}"
            . "Content-Type: application/octet-stream{$crlf}"
            . $crlf
            . $contents . $crlf
            . "--{$boundary}--{$crlf}";

        return $this->streamFactory->createStream($body);
    }

    /**
     * Build the multipart/form-data body for `/api/uploads/multipart/initiate`.
     *
     * Wire shape (mirrors packages/typescript/src/client.ts:714-753):
     *   - `file`           — the first chunk (raw bytes), with filename
     *   - `filename`       — repeated as a plain text field for servers that
     *                        prefer it apart from Content-Disposition
     *   - `total_size`     — total file size in bytes, base-10 string
     *   - `metadata_hint`  — optional JSON-stringified hint for server-side
     *                        first-chunk classification (snake_case keys via
     *                        the generated `*ToJSON` shape)
     */
    private function buildMultipartInitiateBody(
        string $boundary,
        string $filePath,
        string $fileName,
        int $firstChunkSize,
        int $totalSize,
        ?\Gisl\Generated\OpenApi\Model\MultipartInitiateRequestMetadataHint $metadataHint,
    ): \Psr\Http\Message\StreamInterface {
        if (\preg_match('/["\r\n\x00]/', $fileName) === 1) {
            throw new GislConfigError(
                'Filename contains illegal characters for multipart Content-Disposition '
                . '(no `"`, CR, LF, or NUL allowed): ' . \var_export($fileName, true),
            );
        }

        $firstChunkBytes = $this->readChunk($filePath, 0, $firstChunkSize);

        $crlf = "\r\n";
        $body = "--{$boundary}{$crlf}"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"{$fileName}\"{$crlf}"
            . "Content-Type: application/octet-stream{$crlf}"
            . $crlf
            . $firstChunkBytes . $crlf
            . "--{$boundary}{$crlf}"
            . "Content-Disposition: form-data; name=\"filename\"{$crlf}"
            . $crlf
            . $fileName . $crlf
            . "--{$boundary}{$crlf}"
            . "Content-Disposition: form-data; name=\"total_size\"{$crlf}"
            . $crlf
            . $totalSize . $crlf;

        if ($metadataHint !== null) {
            // Generated DTOs are tagged-array friendly via ObjectSerializer's
            // sanitizer — produces a stdClass with snake_case keys
            // (`duration_seconds`, `width`, `height`) so the server's
            // first-chunk classifier sees the contract-pinned wire shape, not
            // the camelCase PHP getters. JSON_FORCE_OBJECT keeps even an
            // all-null hint object encoded as `{}` rather than `[]`.
            $hintWire = ObjectSerializer::sanitizeForSerialization($metadataHint);
            try {
                $hintJson = \json_encode($hintWire, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT);
            } catch (\JsonException $e) {
                throw new GislError("Failed to JSON-encode metadata_hint: {$e->getMessage()}", 0, $e);
            }
            $body .= "--{$boundary}{$crlf}"
                . "Content-Disposition: form-data; name=\"metadata_hint\"{$crlf}"
                . $crlf
                . $hintJson . $crlf;
        }

        $body .= "--{$boundary}--{$crlf}";

        return $this->streamFactory->createStream($body);
    }
}
