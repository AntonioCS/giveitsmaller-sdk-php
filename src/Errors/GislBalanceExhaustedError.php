<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

use Gisl\Generated\OpenApi\Model\BalanceExhaustedResponse;

/**
 * 402 Payment Required — caller's credit balance cannot cover the requested
 * operation. Mirrors `packages/typescript/src/errors.ts:GislBalanceExhaustedError`.
 *
 * The typed `$typedPayload` exposes the contract-pinned response shape
 * (`required_action`, `links`, etc.) for caller-side narrowing without
 * re-parsing the raw `$payload` array.
 */
final class GislBalanceExhaustedError extends GislApiError
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
        public readonly BalanceExhaustedResponse $typedPayload,
        array $payload = [],
        ?string $messageKey = null,
        ?string $locale = null,
        ?array $messageParams = null,
        array $responseHeaders = [],
        ?string $contentLanguage = null,
    ) {
        parent::__construct($message, $statusCode, $errorCode, $payload, $messageKey, $locale, $messageParams, $responseHeaders, $contentLanguage);
    }
}
