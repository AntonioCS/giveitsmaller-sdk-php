<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

/**
 * Client-side / SDK input or config validation. Distinct from server-side
 * {@see GislValidationError} (server's validation envelope).
 *
 * Raised by the SDK before reaching the wire — unreadable file path, oversized
 * argument, illegal config value, callable that's not callable, etc. The TS
 * reference does not separate these two cases (TS reuses
 * `GislValidationError` for both); PHP needs a distinct class so the
 * server-side typed-payload subclass below can inherit from
 * {@see GislApiError} without colliding with the client-side guard.
 *
 * Subclassable: {@see GislMissingCredentialsError} and
 * {@see GislFeatureRequiresAuthError} extend this class so callers
 * catching `GislConfigError` for local configuration failures also catch
 * those two specialised cases. Mirrors the TS hierarchy
 * (`packages/typescript/src/errors.ts:386-417`).
 */
class GislConfigError extends GislError
{
}
