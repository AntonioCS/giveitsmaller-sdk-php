<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

/**
 * 4xx / 5xx response carrying a typed error envelope (`{ success: false,
 * error: "...", details: [...] }`).
 *
 * Mirrors `packages/typescript/src/errors.ts:GislApiError`. The full subclass
 * tree (balance, tier, feature-not-available, workflow-expired) lands with
 * the i18n triple support in VOxtu0RZ-B; this scaffold ships the base class
 * + {@see GislAuthError} only.
 */
class GislApiError extends GislError
{
    /**
     * @param int                  $statusCode HTTP status code.
     * @param string               $errorCode  Wire-stable machine code from
     *                                         `error` field. Never localised.
     * @param array<string, mixed> $payload    Full decoded envelope body for
     *                                         caller-side narrowing (`details`,
     *                                         `message_key`, `locale`,
     *                                         `message_params` when present).
     */
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly string $errorCode,
        public readonly array $payload = [],
    ) {
        parent::__construct($message);
    }
}
