<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

use Gisl\Generated\OpenApi\Model\FeatureTierRestrictedResponse;

/**
 * 403 Forbidden with `error_type: "feature_tier_restricted"` — the caller's
 * tier permits the endpoint but not one or more features within the
 * requested workflow (e.g. free tier requesting a watermark on a paid
 * operation). Mirrors
 * `packages/typescript/src/errors.ts:GislFeatureTierRestrictedError`.
 *
 * The typed `$typedPayload->violations` carries the per-feature breakdown
 * so a caller can show "feature X requires tier Y" rather than a generic
 * upgrade prompt.
 */
final class GislFeatureTierRestrictedError extends GislApiError
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
        public readonly FeatureTierRestrictedResponse $typedPayload,
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
