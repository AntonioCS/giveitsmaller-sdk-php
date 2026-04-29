<?php

declare(strict_types=1);

namespace Gisl\Sdk;

/**
 * Immutable configuration for {@see GislClient}.
 *
 * Multipart-related fields (`multipartThreshold`, `multipartConcurrency`,
 * `multipartMaxAttempts`, `multipartRetryBaseMs`) are read by the constructor
 * and held for the multipart upload path that arrives in VOxtu0RZ-B2. They are
 * sanitised here so the contract is locked before that code lands.
 *
 * Note on `$timeoutMs`: the SDK records the value but does NOT enforce it
 * directly — PSR-18 has no standardised timeout knob, so concrete enforcement
 * lives on the injected HTTP client (Guzzle: `timeout`/`connect_timeout`;
 * Symfony HttpClient: `timeout`). Callers wanting strict enforcement should
 * pre-configure their PSR-18 client. The field is exposed so consumers can
 * read back what they configured (and so that VOxtu0RZ-B2's retry loop has
 * the deadline in scope when it lands).
 *
 * Sanitisation contract (mirrors packages/typescript/src/client.ts:232-265):
 *   timeout              < 1   -> default; otherwise the integer.
 *   multipartThreshold   < 1   -> default. Floor at the 8 MiB first-chunk
 *                                 contract enforced by the multipart path.
 *   multipartConcurrency < 1   -> default (NOT 1) — zero workers ships an
 *                                 incomplete parts array which the server
 *                                 then rejects, silent corruption from the
 *                                 caller's perspective. Garbage input
 *                                 reasonably means "use the default".
 *   multipartMaxAttempts < 1   -> 1 (one attempt = no retry).
 *   multipartRetryBaseMs < 0   -> 0 (opt-out of backoff).
 */
final class GislClientConfig
{
    public const DEFAULT_TIMEOUT_MS = 30_000;
    public const DEFAULT_MULTIPART_THRESHOLD_BYTES = 10_000_000; // 10 MB (decimal)
    public const DEFAULT_MULTIPART_FIRST_CHUNK_SIZE_BYTES = 8_388_608; // 8 MiB
    public const DEFAULT_MULTIPART_CONCURRENCY = 4;
    public const DEFAULT_MULTIPART_MAX_ATTEMPTS = 3;
    public const DEFAULT_MULTIPART_RETRY_BASE_MS = 500;

    public readonly string $baseUrl;
    public readonly ?string $apiKey;
    /** @var array<string, string> */
    public readonly array $headers;
    /** Timeout in milliseconds. Advisory in this scaffold — see class docblock. */
    public readonly int $timeoutMs;
    public readonly bool $useSessionCookie;
    public readonly int $multipartThresholdBytes;
    public readonly int $multipartConcurrency;
    public readonly int $multipartMaxAttempts;
    public readonly int $multipartRetryBaseMs;

    /**
     * @param array<string, string> $headers Extra headers merged into every
     *                                       request (custom User-Agent,
     *                                       tracing IDs, etc.).
     * @param int|null $timeout              Per-request timeout in
     *                                       milliseconds (matches the TS
     *                                       SDK's `timeout` field). Stored
     *                                       in `$timeoutMs`. Advisory in
     *                                       this scaffold — see class
     *                                       docblock.
     */
    public function __construct(
        string $baseUrl,
        ?string $apiKey = null,
        array $headers = [],
        ?int $timeout = null,
        bool $useSessionCookie = false,
        ?int $multipartThresholdBytes = null,
        ?int $multipartConcurrency = null,
        ?int $multipartMaxAttempts = null,
        ?int $multipartRetryBaseMs = null,
    ) {
        // Strip a trailing slash so the request loop can concatenate
        // /api/... paths without duplicating separators.
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->headers = $headers;

        // useSessionCookie wires up cookie-credentialled fetches for browser
        // SPA flows (login() / logout()). The auth/login + auth/logout
        // methods themselves land in VOxtu0RZ-B2, so accepting `true` here
        // would silently ship unauthenticated requests until B lands —
        // fail-open footgun. Reject loudly until the auth surface arrives.
        if ($useSessionCookie === true) {
            throw new \Gisl\Sdk\Errors\GislValidationError(
                'GislClientConfig::$useSessionCookie is not yet implemented in this scaffold; lands with login/logout in VOxtu0RZ-B2.',
            );
        }
        $this->useSessionCookie = $useSessionCookie;

        $this->timeoutMs = $timeout !== null && $timeout >= 1
            ? $timeout
            : self::DEFAULT_TIMEOUT_MS;

        $this->multipartThresholdBytes = self::sanitiseThreshold($multipartThresholdBytes);
        $this->multipartConcurrency = self::sanitiseConcurrency($multipartConcurrency);
        $this->multipartMaxAttempts = self::sanitiseAttempts($multipartMaxAttempts);
        $this->multipartRetryBaseMs = self::sanitiseRetryBaseMs($multipartRetryBaseMs);
    }

    private static function sanitiseThreshold(?int $value): int
    {
        if ($value === null || $value < 1) {
            return self::DEFAULT_MULTIPART_THRESHOLD_BYTES;
        }
        // Floor at the 8 MiB first-chunk contract — sub-8MB threshold would
        // route a file too small to satisfy the multipart initiate's
        // first-chunk shape. Mirrors packages/typescript/src/client.ts:332-335.
        return max($value, self::DEFAULT_MULTIPART_FIRST_CHUNK_SIZE_BYTES);
    }

    private static function sanitiseConcurrency(?int $value): int
    {
        if ($value === null || $value < 1) {
            return self::DEFAULT_MULTIPART_CONCURRENCY;
        }
        return $value;
    }

    private static function sanitiseAttempts(?int $value): int
    {
        if ($value === null) {
            return self::DEFAULT_MULTIPART_MAX_ATTEMPTS;
        }
        // Floor at 1 (one attempt = no retry). Mirrors sanitiseAttempts in
        // packages/typescript/src/client.ts:235-238 — but PHP int can't carry
        // NaN/Infinity, so the only branch needed is the < 1 guard.
        return max(1, $value);
    }

    private static function sanitiseRetryBaseMs(?int $value): int
    {
        if ($value === null) {
            return self::DEFAULT_MULTIPART_RETRY_BASE_MS;
        }
        // Floor at 0 (zero opts out of backoff entirely). Mirrors
        // sanitiseBaseMs in packages/typescript/src/client.ts:243-246.
        return max(0, $value);
    }
}
