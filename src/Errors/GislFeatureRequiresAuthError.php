<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

/**
 * Raised when an operation is invoked through a code path that has no
 * credentials AND is not on the anonymous-capable allowlist. Carries
 * the operation name so callers can recover by switching to an
 * authenticated factory or routing to a different op.
 *
 * Today the only consumer is the parking-gate on
 * {@see \Gisl\Sdk\Gisl::internalAnonymous()} — while
 * {@see \Gisl\Sdk\Gisl::ANONYMOUS_ALLOWLIST} is empty, every anonymous
 * invocation raises this error before any client is returned. The
 * per-operation allowlist gate lands with the P2 operation builder.
 *
 * Mirrors `packages/typescript/src/errors.ts` `GislFeatureRequiresAuthError`.
 */
final class GislFeatureRequiresAuthError extends GislConfigError
{
    public function __construct(
        public readonly string $operation,
        string $message,
    ) {
        parent::__construct($message);
    }
}
