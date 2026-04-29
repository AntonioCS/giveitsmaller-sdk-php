<?php

declare(strict_types=1);

namespace Gisl\Sdk;

use Gisl\Generated\OpenApi\Model\UploadResponse;
use Gisl\Generated\OpenApi\Model\WorkflowCreateResponse;
use Gisl\Generated\OpenApi\Model\WorkflowStatusResponse;
use Gisl\Generated\OpenApi\ObjectSerializer;
use Gisl\Sdk\Errors\GislApiError;
use Gisl\Sdk\Errors\GislAuthError;
use Gisl\Sdk\Errors\GislError;
use Gisl\Sdk\Errors\GislNetworkError;
use Gisl\Sdk\Errors\GislValidationError;
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
 *   - {@see uploadFile}            single-shot upload (POST /api/uploads)
 *   - {@see createWorkflow}        POST /api/workflows
 *   - {@see getWorkflowStatus}     GET /api/workflows/{id}/status
 *
 * Multipart upload, SSE consumer, webhook verification, downloads, polling,
 * and the full method surface arrive in `VOxtu0RZ-B`.
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
     * Upload a file. Single-shot only in this scaffold; multipart routing
     * arrives in VOxtu0RZ-B.
     *
     * @param string|resource $filePathOrResource Filesystem path string OR a
     *                                            stream resource. Resources
     *                                            throw `GislValidationError`
     *                                            in this scaffold; that path
     *                                            opens up in VOxtu0RZ-B.
     */
    public function uploadFile(
        mixed $filePathOrResource,
        ?UploadOptions $options = null,
    ): UploadResponse {
        unset($options); // VOxtu0RZ-B will consume options.
        if (\is_resource($filePathOrResource)) {
            throw new GislValidationError(
                'Stream-resource uploadFile() is deferred to VOxtu0RZ-B; pass a filesystem path for now.',
            );
        }
        if (!\is_string($filePathOrResource)) {
            throw new GislValidationError(
                'uploadFile expected a string filesystem path or a stream resource; got ' . \get_debug_type($filePathOrResource) . '.',
            );
        }

        $filePath = $filePathOrResource;
        if (!\is_file($filePath) || !\is_readable($filePath)) {
            throw new GislValidationError("File not found or not readable: {$filePath}");
        }

        $size = \filesize($filePath);
        if ($size === false) {
            throw new GislValidationError("Unable to stat file: {$filePath}");
        }
        if ($size > $this->config->multipartThresholdBytes) {
            throw new GislValidationError(
                "File size {$size} bytes exceeds multipartThreshold "
                . "({$this->config->multipartThresholdBytes} bytes); multipart upload arrives in VOxtu0RZ-B.",
            );
        }

        $boundary = $this->generateMultipartBoundary();
        $body = $this->buildSingleShotMultipartBody(
            boundary: $boundary,
            filePath: $filePath,
            fileName: \basename($filePath),
        );

        $request = $this->buildRequest(
            method: 'POST',
            path: '/api/uploads',
            body: $body,
            extraHeaders: ['Content-Type' => "multipart/form-data; boundary={$boundary}"],
        );

        /** @var array<string, mixed> $data */
        $data = $this->sendAndUnwrap($request);
        return $this->hydrate(UploadResponse::class, $data);
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
            // landing in VOxtu0RZ-B (submitContact, etc.) return void in
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

        $errorClass = $statusCode === 401 ? GislAuthError::class : GislApiError::class;
        throw new $errorClass(
            $message,
            $statusCode,
            $errorCode,
            $decoded,
        );
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
            throw new GislValidationError(
                'Filename contains illegal characters for multipart Content-Disposition '
                . '(no `"`, CR, LF, or NUL allowed): ' . \var_export($fileName, true),
            );
        }

        $contents = \file_get_contents($filePath);
        if ($contents === false) {
            throw new GislValidationError("Unable to read file: {$filePath}");
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
}
