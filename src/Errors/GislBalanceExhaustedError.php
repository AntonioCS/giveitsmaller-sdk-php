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
    public function __construct(
        string $message,
        int $statusCode,
        string $errorCode,
        public readonly BalanceExhaustedResponse $typedPayload,
        array $payload = [],
        ?string $messageKey = null,
        ?string $locale = null,
        ?array $messageParams = null,
    ) {
        parent::__construct($message, $statusCode, $errorCode, $payload, $messageKey, $locale, $messageParams);
    }
}
