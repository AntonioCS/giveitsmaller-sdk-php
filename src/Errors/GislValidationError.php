<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

use Gisl\Generated\OpenApi\Model\ValidationErrorEnvelope;

/**
 * Server-side validation failure (HTTP 4xx with a typed validation envelope:
 * `{ success: false, error, details: [{ message, field?, operation?,
 * option?, ... }] }`). Mirrors
 * `packages/typescript/src/errors.ts:GislValidationError`.
 *
 * Distinct from {@see GislConfigError} (client-side / SDK input or config
 * validation). TS uses a single class for both because TS has no separate
 * "before-the-wire" guard surface; PHP needs the split because the typed
 * payload subclass below MUST inherit from {@see GislApiError} for the
 * dispatch tree to make sense.
 *
 * Detection in `GislClient::unwrapEnvelope` is shape-based on the wire
 * `details` field: each entry must carry at least a `message` string.
 * Envelopes whose `details` use a different key (e.g. legacy `reason` from
 * the v1 contract) intentionally fall through to the generic
 * {@see GislApiError} so existing callers reading `$e->payload['details']`
 * keep working.
 */
final class GislValidationError extends GislApiError
{
    public function __construct(
        string $message,
        int $statusCode,
        string $errorCode,
        public readonly ValidationErrorEnvelope $typedPayload,
        array $payload = [],
        ?string $messageKey = null,
        ?string $locale = null,
        ?array $messageParams = null,
    ) {
        parent::__construct($message, $statusCode, $errorCode, $payload, $messageKey, $locale, $messageParams);
    }
}
