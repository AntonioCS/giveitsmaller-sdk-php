<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

use Gisl\Generated\OpenApi\Model\FeatureNotAvailableResponse;

/**
 * 422 Unprocessable Entity with `error_type: "feature_not_available"` — the
 * caller requested a feature that the server cannot fulfil at all in the
 * current environment (planned / experimental / temporarily disabled). This
 * is distinct from {@see GislFeatureTierRestrictedError} (which is a tier
 * gate, not an availability gate). Mirrors
 * `packages/typescript/src/errors.ts:GislFeatureNotAvailableError`.
 */
final class GislFeatureNotAvailableError extends GislApiError
{
    public function __construct(
        string $message,
        int $statusCode,
        string $errorCode,
        public readonly FeatureNotAvailableResponse $typedPayload,
        array $payload = [],
        ?string $messageKey = null,
        ?string $locale = null,
        ?array $messageParams = null,
    ) {
        parent::__construct($message, $statusCode, $errorCode, $payload, $messageKey, $locale, $messageParams);
    }
}
