<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

use Gisl\Generated\OpenApi\Model\WorkflowExpiredResponse;

/**
 * 422 Unprocessable Entity with `error_type: "workflow_expired"` — the
 * referenced workflow has passed its server-side TTL and is no longer
 * resumable. Mirrors
 * `packages/typescript/src/errors.ts:GislWorkflowExpiredError`.
 *
 * The typed `$typedPayload->expiredAt` exposes the moment the workflow
 * lapsed so a caller can render an accurate "expired N minutes ago"
 * message without re-parsing the raw payload.
 */
final class GislWorkflowExpiredError extends GislApiError
{
    public function __construct(
        string $message,
        int $statusCode,
        string $errorCode,
        public readonly WorkflowExpiredResponse $typedPayload,
        array $payload = [],
        ?string $messageKey = null,
        ?string $locale = null,
        ?array $messageParams = null,
    ) {
        parent::__construct($message, $statusCode, $errorCode, $payload, $messageKey, $locale, $messageParams);
    }
}
