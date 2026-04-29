<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Parity;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Stub PSR-18 client used by the parity runner.
 *
 * Mirrors the behaviour of `createFetchStub` at
 * `packages/typescript/tests/parity/fetch-stub.ts`: every outbound request
 * is captured into a normalised {@see CapturedRequest} shape and the next
 * queued response is returned. An exhausted queue throws so a missing
 * fixture-side response surfaces as a loud test failure.
 */
final class StubPsr18Client implements ClientInterface
{
    /** @var list<array<string, mixed>> */
    private array $responseQueue;

    /** @var list<CapturedRequest> */
    private array $captured = [];

    private ?string $fixtureFile;

    /**
     * @param list<array<string, mixed>> $responses Fixture response objects, in order.
     */
    public function __construct(array $responses, ?string $fixtureFile)
    {
        $this->responseQueue = $responses;
        $this->fixtureFile = $fixtureFile;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->captured[] = self::captureRequest($request);

        if (\count($this->responseQueue) === 0) {
            throw new \RuntimeException(
                'StubPsr18Client: response queue exhausted on request #' . \count($this->captured)
                . ' (' . $request->getMethod() . ' ' . (string) $request->getUri() . ')',
            );
        }

        $next = \array_shift($this->responseQueue);
        return self::buildResponse($next, $this->fixtureFile);
    }

    /**
     * @return list<CapturedRequest>
     */
    public function captured(): array
    {
        return $this->captured;
    }

    private static function captureRequest(RequestInterface $request): CapturedRequest
    {
        $uri = $request->getUri();
        $url = (string) $uri;
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[\strtolower($name)] = \implode(', ', $values);
        }

        $query = [];
        $rawQuery = $uri->getQuery();
        if ($rawQuery !== '') {
            // parse_str collapses repeated keys via PHP's `[]` convention
            // which would munge our flat shape. Manual split keeps the
            // canonical multi-value form.
            foreach (\explode('&', $rawQuery) as $pair) {
                if ($pair === '') {
                    continue;
                }
                $eq = \strpos($pair, '=');
                if ($eq === false) {
                    $k = \rawurldecode($pair);
                    $v = '';
                } else {
                    $k = \rawurldecode(\substr($pair, 0, $eq));
                    $v = \rawurldecode(\substr($pair, $eq + 1));
                }
                $query[$k] ??= [];
                $query[$k][] = $v;
            }
        }

        $contentType = $headers['content-type'] ?? '';
        $body = self::captureBody($request, $contentType);
        $multipartParts = [];
        if (($body['type'] ?? '') === 'multipart') {
            /** @var list<array<string, mixed>> $multipartParts */
            $multipartParts = $body['parts'] ?? [];
            $body = ['type' => 'multipart'];
        }

        return new CapturedRequest(
            method: $request->getMethod(),
            url: $url,
            path: $uri->getPath(),
            query: $query,
            headers: $headers,
            body: $body,
            multipartParts: $multipartParts,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function captureBody(RequestInterface $request, string $contentType): array
    {
        $stream = $request->getBody();
        // Rewind so a subsequent re-read in the SDK still works (PSR-7
        // streams are typically rewindable; defensively guard).
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $raw = (string) $stream;
        if ($raw === '') {
            return ['type' => 'empty'];
        }

        $lowerCt = \strtolower($contentType);

        if (\str_starts_with($lowerCt, 'multipart/form-data')) {
            $boundary = self::extractBoundary($contentType);
            if ($boundary === null) {
                throw new \RuntimeException(
                    "StubPsr18Client: multipart Content-Type missing boundary: {$contentType}",
                );
            }
            return ['type' => 'multipart', 'parts' => self::parseMultipart($raw, $boundary)];
        }

        if (\str_contains($lowerCt, 'application/json') || \str_contains($lowerCt, '+json')) {
            try {
                /** @var mixed $parsed */
                $parsed = \json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
                return ['type' => 'json', 'value' => $parsed];
            } catch (\JsonException) {
                return ['type' => 'text', 'value' => $raw];
            }
        }

        // Treat anything else with body bytes as raw — PSR-7 raw uploads
        // (presigned PUTs in the multipart flow) hit this branch.
        return ['type' => 'raw', 'bytes' => $raw];
    }

    private static function extractBoundary(string $contentType): ?string
    {
        if (\preg_match('/boundary="?([^";]+)"?/i', $contentType, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    /**
     * Naive but spec-compliant multipart/form-data parser. Splits on the
     * boundary, drops the prologue/epilogue, and decodes one part header
     * block per segment. Cannot rely on a generic library: PSR-18 stub
     * tests need to inspect the bytes the SDK actually sent.
     *
     * @return list<array<string, mixed>>
     */
    private static function parseMultipart(string $raw, string $boundary): array
    {
        $delim = "--{$boundary}";
        $segments = \explode($delim, $raw);
        $parts = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === "--\r\n" || $segment === "--") {
                continue;
            }
            // Strip the leading CRLF after the delimiter.
            if (\str_starts_with($segment, "\r\n")) {
                $segment = \substr($segment, 2);
            }
            // The trailing `\r\n` before the next delimiter belongs to the
            // boundary line, not the part content.
            if (\str_ends_with($segment, "\r\n")) {
                $segment = \substr($segment, 0, -2);
            }
            $headerEnd = \strpos($segment, "\r\n\r\n");
            if ($headerEnd === false) {
                continue;
            }
            $headerBlock = \substr($segment, 0, $headerEnd);
            $content = \substr($segment, $headerEnd + 4);

            $name = null;
            $filename = null;
            $contentType = null;
            foreach (\explode("\r\n", $headerBlock) as $line) {
                if (\stripos($line, 'content-disposition:') === 0) {
                    if (\preg_match('/name="([^"]*)"/', $line, $m) === 1) {
                        $name = $m[1];
                    }
                    if (\preg_match('/filename="([^"]*)"/', $line, $m) === 1) {
                        $filename = $m[1];
                    }
                } elseif (\stripos($line, 'content-type:') === 0) {
                    $contentType = \trim(\substr($line, \strlen('content-type:')));
                }
            }

            if ($name === null) {
                continue;
            }

            $part = ['name' => $name];
            if ($filename !== null) {
                $part['filename'] = $filename;
            }
            if ($contentType !== null) {
                $part['contentType'] = $contentType;
            }
            // For file parts (filename present) treat the content as raw
            // bytes; for plain form fields with a filename absent and
            // content-type absent, treat as text. The runner's comparator
            // distinguishes between the two when the fixture declares
            // `kind: text` vs `kind: bytes`.
            if ($filename !== null || ($contentType !== null && $contentType !== 'text/plain')) {
                $part['bytes'] = $content;
            } else {
                $part['text'] = $content;
            }
            $parts[] = $part;
        }
        return $parts;
    }

    /**
     * @param array<string, mixed> $res
     */
    private static function buildResponse(array $res, ?string $fixtureFile): ResponseInterface
    {
        $status = (int) ($res['status'] ?? 200);
        $headers = [];
        if (isset($res['headers']) && \is_array($res['headers'])) {
            foreach ($res['headers'] as $name => $value) {
                if (\is_string($name)) {
                    $headers[$name] = (string) $value;
                }
            }
        }

        if (!isset($res['body']) || !\is_array($res['body'])) {
            return new Response($status, $headers);
        }
        /** @var array<string, mixed> $body */
        $body = $res['body'];
        $type = (string) ($body['type'] ?? 'empty');

        if ($type === 'empty') {
            return new Response($status, $headers);
        }
        if ($type === 'json') {
            if (!isset($headers['content-type']) && !isset($headers['Content-Type'])) {
                $headers['Content-Type'] = 'application/json';
            }
            return new Response(
                $status,
                $headers,
                \json_encode($body['value'] ?? null, JSON_UNESCAPED_SLASHES) ?: '',
            );
        }
        if ($type === 'text') {
            if (!isset($headers['content-type']) && !isset($headers['Content-Type'])) {
                $headers['Content-Type'] = 'text/plain';
            }
            return new Response($status, $headers, (string) ($body['value'] ?? ''));
        }
        if ($type === 'sse_stream') {
            if (!isset($headers['content-type']) && !isset($headers['Content-Type'])) {
                $headers['Content-Type'] = 'text/event-stream';
            }
            $chunks = [];
            if (isset($body['chunks']) && \is_array($body['chunks'])) {
                foreach ($body['chunks'] as $c) {
                    $chunks[] = (string) $c;
                }
            }
            // Concat is fine — the SSE parser handles arbitrary chunk
            // boundaries internally, including the multi-chunk fixture.
            // Stream::for ensures the body is rewindable.
            return new Response($status, $headers, Utils::streamFor(\implode('', $chunks)));
        }
        if ($type === 'raw') {
            $bytesValue = $body['content'] ?? null;
            if (!\is_array($bytesValue)) {
                throw new \RuntimeException('StubPsr18Client: raw response body missing content.');
            }
            /** @var array<string, mixed> $bytesValue */
            return new Response($status, $headers, BytesDecoder::decode($bytesValue, $fixtureFile));
        }
        throw new \RuntimeException("StubPsr18Client: unsupported response body type \"{$type}\"");
    }
}
