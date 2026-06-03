<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

use Gisl\Generated\OpenApi\Model\AuthRejectionEnvelope;

/**
 * 422 Unprocessable Entity — domain rejection on auth side-effect endpoints
 * (register / verify-email / api-keys duplicate-or-invalid; profile PATCH email
 * unchanged). Flat AuthRejectionEnvelope, no details[]. Mirrors
 * `packages/typescript/src/errors.ts:GislAuthRejectionError`.
 *
 * The typed `$typedPayload` exposes the contract-pinned `error_type`
 * discriminator (`unprocessable_entity`, `email_same`) for caller-side
 * narrowing without re-parsing the raw `$payload` array.
 */
final class GislAuthRejectionError extends GislApiError
{
    public function __construct(
        string $message,
        int $statusCode,
        string $errorCode,
        public readonly AuthRejectionEnvelope $typedPayload,
        array $payload = [],
        ?string $messageKey = null,
        ?string $locale = null,
        ?array $messageParams = null,
    ) {
        parent::__construct($message, $statusCode, $errorCode, $payload, $messageKey, $locale, $messageParams);
    }

    /**
     * The typed flat rejection envelope (no `details[]`).
     */
    public function getAuthRejection(): AuthRejectionEnvelope
    {
        return $this->typedPayload;
    }

    /**
     * The auth-422 `oneOf` discriminator: `unprocessable_entity` or `email_same`.
     */
    public function getErrorType(): string
    {
        return $this->typedPayload->getErrorType();
    }
}
