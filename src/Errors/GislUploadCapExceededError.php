<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

use Gisl\Generated\OpenApi\Model\UploadDurationExceedsTierResponse;
use Gisl\Generated\OpenApi\Model\UploadSizeExceedsTierResponse;

/**
 * The upload exceeds a size or duration cap. Covers all three server shapes:
 * - 422 `upload_size_exceeds_tier`     (kind `size_tier`, typed payload present)
 * - 422 `upload_duration_exceeds_tier` (kind `duration_tier`, typed payload present)
 * - 413 absolute across-tier cap       (kind `absolute_413`, NO typed payload)
 *
 * Mirrors `packages/typescript/src/errors.ts:GislUploadCapExceededError`.
 *
 * CONSCIOUS DEVIATION (mirrors the TS-side note): every other structured
 * subclass binds exactly one typed payload. This one binds a size|duration
 * union — and none at all for 413, which the contract models as a plain
 * `ErrorEnvelope` with no `error_type` discriminator. The card mandates this
 * single class name and SDK-3/E2E-1 are blocked-on it, so splitting into
 * size/duration subclasses would break a cross-ticket naming contract; 413
 * could not be covered uniformly by a one-payload-per-class split anyway.
 * The `$kind` discriminant + a nullable union `$typedPayload` is the
 * deliberate trade-off.
 */
final class GislUploadCapExceededError extends GislApiError
{
    public const KIND_SIZE_TIER = 'size_tier';
    public const KIND_DURATION_TIER = 'duration_tier';
    public const KIND_ABSOLUTE_413 = 'absolute_413';
    /**
     * SDK-3 (Wb6ebOMM) — 422 `FILE_TOO_LARGE_FOR_MULTIPART`: pre-S3 capacity
     * reject on the resume-support presign endpoint (more parts than the
     * manifest can ever accept). No typed payload today. Adding a new kind
     * value here is an additive extension on the discriminant (TS 0.5.0 /
     * PHP 0.3.0); existing `instanceof GislUploadCapExceededError` catches
     * keep working. Consumer exhaustive-switch warning is captured on the
     * TS-side `GislUploadCapKind` docblock; PHP has no exhaustive-string-
     * union construct so this is silent here.
     */
    public const KIND_V2_MULTIPART = 'cap_v2_multipart';

    /**
     * @param string                $kind             One of the KIND_* constants.
     *                                                `absolute_413` always carries a null
     *                                                `$typedPayload` (413 has no structured
     *                                                envelope on the wire).
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
        public readonly string $kind,
        public readonly UploadSizeExceedsTierResponse|UploadDurationExceedsTierResponse|null $typedPayload,
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
