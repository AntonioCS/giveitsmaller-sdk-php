<?php

declare(strict_types=1);

namespace Gisl\Sdk\Http;

use Gisl\Sdk\Errors\GislError;
use Gisl\Sdk\Errors\GislMultipartPartError;

/**
 * {@see MultipartPartUploader} that PUTs chunks concurrently via `curl_multi`
 * (P6+ `z9bDW2iH`). Default uploader when ext-curl is present and concurrency
 * > 1; otherwise {@see GislClient} falls back to its sequential PSR-18 loop.
 *
 * The part PUTs are pre-signed S3 URLs (no SDK auth/base-url), so driving them
 * with raw curl handles is independent of the injected PSR-18 client. Mirrors
 * the sequential path's contract exactly: bounded retry with full-jitter
 * backoff, lazy offset/length reads (never buffers all parts — at most
 * `$concurrency` chunks in flight), per-part completion callback, and the same
 * typed errors ({@see GislMultipartPartError} on retry-exhaustion / read
 * failure; {@see GislError} on non-retryable HTTP / missing ETag). On the first
 * fatal part it cancels the in-flight handles and throws.
 *
 * @internal
 */
final class CurlMultiPartUploader implements MultipartPartUploader
{
    private const BACKOFF_CAP_MS = 30_000;
    private const RETRYABLE_STATUSES = [408, 429];

    public function __construct(
        private readonly int $maxAttempts,
        private readonly int $retryBaseMs,
    ) {
    }

    public static function isSupported(): bool
    {
        return \extension_loaded('curl');
    }

    public function uploadParts(
        string $filePath,
        array $parts,
        string $uploadId,
        int $concurrency,
        callable $onPartComplete,
    ): array {
        if (!self::isSupported()) {
            throw new GislError('CurlMultiPartUploader requires ext-curl; none loaded.');
        }
        $limit = \max(1, $concurrency);

        /** @var array<int, string> $etags partNumber => etag */
        $etags = [];
        // Pending part indices (into $parts) not yet started.
        $pending = \array_keys($parts);
        // handle (int id) => running per-part state.
        /** @var array<int, array{idx: int, partNumber: int, length: int, attempt: int, handle: \CurlHandle, headers: string, fh: resource}> $active */
        $active = [];

        $mh = \curl_multi_init();
        try {
            // Prime the window.
            while (\count($active) < $limit && $pending !== []) {
                $this->startPart($mh, $filePath, $parts, \array_shift($pending), $uploadId, $active, attempt: 0);
            }

            do {
                // Drive the transfers.
                do {
                    $status = \curl_multi_exec($mh, $running);
                } while ($status === \CURLM_CALL_MULTI_PERFORM);
                if ($status !== \CURLM_OK) {
                    throw new GislError('curl_multi_exec failed: ' . \curl_multi_strerror($status));
                }

                // Reap finished handles.
                while (($info = \curl_multi_info_read($mh)) !== false) {
                    /** @var \CurlHandle $done */
                    $done = $info['handle'];
                    $id = \spl_object_id($done);
                    $state = $active[$id];
                    unset($active[$id]);
                    \curl_multi_remove_handle($mh, $done);

                    $outcome = $this->classify($info['result'], $done, $state);
                    \fclose($state['fh']);
                    // PHP 8.0+: CurlHandle is GC-freed; curl_close is a
                    // deprecated no-op. Removing from the multi + dropping the
                    // reference is sufficient.

                    if ($outcome['kind'] === 'ok') {
                        $etags[$state['partNumber']] = $outcome['etag'];
                        $onPartComplete($state['partNumber'], $state['length']);
                    } elseif ($outcome['kind'] === 'fatal') {
                        $this->abort($mh, $active);
                        throw $outcome['error'];
                    } else { // 'retry'
                        $nextAttempt = $state['attempt'] + 1;
                        if ($nextAttempt >= $this->maxAttempts) {
                            $this->abort($mh, $active);
                            throw new GislMultipartPartError(
                                "S3 chunk upload failed for part {$state['partNumber']} after {$this->maxAttempts} attempts: {$outcome['detail']}",
                                $state['partNumber'],
                                $uploadId,
                            );
                        }
                        $this->sleepBackoff($nextAttempt);
                        $this->startPart($mh, $filePath, $parts, $state['idx'], $uploadId, $active, $nextAttempt);
                    }

                    // Refill the window from pending.
                    while (\count($active) < $limit && $pending !== []) {
                        $this->startPart($mh, $filePath, $parts, \array_shift($pending), $uploadId, $active, attempt: 0);
                    }
                }

                if ($active !== [] || $pending !== []) {
                    // Block until a transfer is ready. curl_multi_select returns
                    // -1 when there is no waitable fd yet (e.g. just after
                    // add_handle, before exec primes the socket, or during
                    // c-ares name resolution) — in that case it does NOT block,
                    // so a short sleep avoids a transient busy-spin.
                    if (\curl_multi_select($mh, 1.0) === -1) {
                        \usleep(100);
                    }
                }
            } while ($active !== [] || $pending !== []);
        } finally {
            $this->abort($mh, $active);
            \curl_multi_close($mh);
        }

        return $etags;
    }

    /**
     * @param list<array{partNumber: int, url: string, offset: int, length: int}> $parts
     * @param array<int, array{idx: int, partNumber: int, length: int, attempt: int, handle: \CurlHandle, headers: string, fh: resource}> $active
     */
    private function startPart(
        \CurlMultiHandle $mh,
        string $filePath,
        array $parts,
        int $idx,
        string $uploadId,
        array &$active,
        int $attempt,
    ): void {
        $descriptor = $parts[$idx];
        $partNumber = $descriptor['partNumber'];
        $url = $descriptor['url'];
        if ($url === '') {
            throw new GislError("Presigned URL part {$partNumber} has empty url.");
        }

        $fh = @\fopen($filePath, 'rb');
        if ($fh === false) {
            throw new GislMultipartPartError(
                "Failed to read bytes for part {$partNumber}: cannot open {$filePath}.",
                $partNumber,
                $uploadId,
            );
        }
        if (\fseek($fh, $descriptor['offset']) !== 0) {
            \fclose($fh);
            throw new GislMultipartPartError(
                "Failed to read bytes for part {$partNumber}: seek to {$descriptor['offset']} failed.",
                $partNumber,
                $uploadId,
            );
        }

        $ch = \curl_init();
        if ($ch === false) {
            \fclose($fh);
            throw new GislMultipartPartError(
                "curl_init failed for part {$partNumber}.",
                $partNumber,
                $uploadId,
            );
        }
        // Capture the ETag response header (case-insensitive).
        $headerBuf = '';
        \curl_setopt_array($ch, [
            \CURLOPT_URL => $url,
            \CURLOPT_UPLOAD => true,            // PUT via the read stream
            \CURLOPT_INFILE => $fh,
            \CURLOPT_INFILESIZE => $descriptor['length'],
            \CURLOPT_HTTPHEADER => ['Content-Length: ' . $descriptor['length']],
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_WRITEFUNCTION => static fn ($c, string $d): int => \strlen($d), // drain body
            \CURLOPT_HEADERFUNCTION => function ($c, string $line) use (&$headerBuf): int {
                $headerBuf .= $line;
                return \strlen($line);
            },
        ]);

        $id = \spl_object_id($ch);
        $active[$id] = [
            'idx' => $idx,
            'partNumber' => $partNumber,
            'length' => $descriptor['length'],
            'attempt' => $attempt,
            'handle' => $ch,
            'headers' => &$headerBuf,
            'fh' => $fh,
        ];
        \curl_multi_add_handle($mh, $ch);
    }

    /**
     * @param array{idx: int, partNumber: int, length: int, attempt: int, handle: \CurlHandle, headers: string, fh: resource} $state
     *
     * @return array{kind: 'ok', etag: string}|array{kind: 'retry', detail: string}|array{kind: 'fatal', error: GislError}
     */
    private function classify(int $curlResult, \CurlHandle $handle, array $state): array
    {
        $partNumber = $state['partNumber'];

        if ($curlResult !== \CURLE_OK) {
            // Transport-level failure — retryable (mirrors the PSR-18 path
            // treating ClientExceptionInterface as retryable).
            return ['kind' => 'retry', 'detail' => 'curl error ' . $curlResult . ': ' . \curl_strerror($curlResult)];
        }

        $statusCode = (int) \curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
        if ($statusCode >= 200 && $statusCode < 300) {
            $etag = $this->parseEtag($state['headers']);
            if ($etag === '') {
                return ['kind' => 'fatal', 'error' => new GislError("S3 response missing ETag for part {$partNumber}.")];
            }
            return ['kind' => 'ok', 'etag' => $etag];
        }

        if (!$this->isRetryableStatus($statusCode)) {
            return ['kind' => 'fatal', 'error' => new GislError(
                "S3 chunk upload failed for part {$partNumber}: HTTP {$statusCode} (non-retryable).",
            )];
        }

        return ['kind' => 'retry', 'detail' => "HTTP {$statusCode}"];
    }

    private function parseEtag(string $rawHeaders): string
    {
        foreach (\explode("\r\n", $rawHeaders) as $line) {
            if (\stripos($line, 'etag:') === 0) {
                return \trim(\substr($line, 5));
            }
        }
        return '';
    }

    private function isRetryableStatus(int $status): bool
    {
        return \in_array($status, self::RETRYABLE_STATUSES, true) || ($status >= 500 && $status < 600);
    }

    /**
     * @param array<int, array{idx: int, partNumber: int, length: int, attempt: int, handle: \CurlHandle, headers: string, fh: resource}> $active
     */
    private function abort(\CurlMultiHandle $mh, array &$active): void
    {
        foreach ($active as $id => $state) {
            \curl_multi_remove_handle($mh, $state['handle']);
            if (\is_resource($state['fh'])) {
                \fclose($state['fh']);
            }
            unset($active[$id]);
        }
    }

    private function sleepBackoff(int $attempt): void
    {
        if ($this->retryBaseMs <= 0) {
            return;
        }
        $exponent = \min($attempt, 20);
        $ceiling = \min($this->retryBaseMs * (1 << $exponent), self::BACKOFF_CAP_MS);
        $delayMs = \random_int(0, \max(0, $ceiling));
        if ($delayMs > 0) {
            \usleep($delayMs * 1000);
        }
    }
}
