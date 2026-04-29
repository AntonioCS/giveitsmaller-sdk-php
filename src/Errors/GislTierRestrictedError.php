<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

use Gisl\Generated\OpenApi\Model\TierRestrictionResponse;

/**
 * 403 Forbidden with `error_type: "tier_restriction"` — the caller's tier
 * does not include the requested capability at all (e.g. free tier hitting
 * an enterprise-only endpoint). Mirrors
 * `packages/typescript/src/errors.ts:GislTierRestrictedError`.
 *
 * The typed `$typedPayload` carries the contract-pinned
 * `restriction_kind` + `current_tier` discriminators used by callers to
 * decide between "upgrade prompt" and "graceful degradation".
 */
final class GislTierRestrictedError extends GislApiError
{
    public function __construct(
        string $message,
        int $statusCode,
        string $errorCode,
        public readonly TierRestrictionResponse $typedPayload,
        array $payload = [],
        ?string $messageKey = null,
        ?string $locale = null,
        ?array $messageParams = null,
    ) {
        parent::__construct($message, $statusCode, $errorCode, $payload, $messageKey, $locale, $messageParams);
    }
}
