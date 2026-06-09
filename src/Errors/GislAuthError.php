<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

use Gisl\Generated\OpenApi\Model\AuthErrorResponse;

/**
 * 401 / 403 authentication failure, optionally carrying a typed
 * `AuthErrorResponse` payload.
 *
 * Mirrors `packages/typescript/src/errors.ts:GislAuthError`. The typed
 * `$typedPayload` exposes the contract-pinned `error_type` discriminator
 * (`invalid_credentials`, `account_locked`, `api_key_invalid`, ...) for
 * caller-side narrowing without re-parsing the raw `$payload` array.
 *
 * Two construction paths land on this same class:
 *   1. Typed dispatch — 401/403 with an `error_type` in the
 *      `AuthErrorType` enum: `$typedPayload` is non-null.
 *   2. Generic-401 fallback — 401 with an unrecognised `error_type` (e.g.
 *      legacy `invalid_api_key`): `$typedPayload` is `null`.
 *
 * Callers narrowing on the typed payload MUST check `$e->typedPayload !== null`
 * before reading typed fields. The readonly property is always defined; only
 * its value differs between the two paths.
 */
class GislAuthError extends GislApiError
{
    /**
     * @param array<string, string> $responseHeaders  HTTP response headers, keys LOWERCASED.
     *                                                Multi-value headers comma-joined.
     *                                                Mirrors {@see GislApiError::$responseHeaders}.
     * @param string|null           $contentLanguage  `Content-Language` response header.
     *                                                DISTINCT from `$locale` (I26 body tag).
     */
    public function __construct(
        string $message,
        int $statusCode,
        string $errorCode,
        array $payload = [],
        ?string $messageKey = null,
        ?string $locale = null,
        ?array $messageParams = null,
        public readonly ?AuthErrorResponse $typedPayload = null,
        array $responseHeaders = [],
        ?string $contentLanguage = null,
    ) {
        parent::__construct($message, $statusCode, $errorCode, $payload, $messageKey, $locale, $messageParams, $responseHeaders, $contentLanguage);
    }
}
