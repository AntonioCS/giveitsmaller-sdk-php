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
 */
final class GislConfigError extends GislError
{
}
