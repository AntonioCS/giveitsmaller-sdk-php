<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

use Gisl\Generated\OpenApi\Model\ProbePendingResponse;

/**
 * 422 Unprocessable Entity with `error_type: "probe_pending"` —
 * `POST /api/workflows` references an upload whose server-side probe
 * has not yet completed. The server rejects rather than silently routing
 * the job as `short_form` (which would hard-fail long video clips).
 *
 * Recovery contract (per contracts ProbePendingResponse docblock):
 * poll `POST /api/uploads/{id}/probe` for the pending upload until
 * `probe_status` is terminal (`ok` → re-`POST /api/workflows` the same
 * request; `corrupt` / `unsupported_codec` → surface the probe error).
 * The `Retry-After` response header (when present) suggests the delay
 * in seconds before the next poll/retry.
 *
 * `typedPayload->getJobRef()` identifies which job in a multi-job request
 * triggered the probe-pending rejection.
 *
 * Mirrors `packages/typescript/src/errors.ts:GislProbePendingError`.
 */
final class GislProbePendingError extends GislApiError
{
    public function __construct(
        string $message,
        int $statusCode,
        string $errorCode,
        public readonly ProbePendingResponse $typedPayload,
        array $payload = [],
        ?string $messageKey = null,
        ?string $locale = null,
        ?array $messageParams = null,
    ) {
        parent::__construct($message, $statusCode, $errorCode, $payload, $messageKey, $locale, $messageParams);
    }
}
