<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

/**
 * Client-side input validation failure — a constraint the SDK enforces
 * before reaching out to the server (e.g. unreadable file path, oversize
 * file when multipart isn't yet supported, illegal config value).
 *
 * Mirrors `packages/typescript/src/errors.ts:GislValidationError`. Distinct
 * from {@see GislApiError} which carries a server-side typed envelope.
 */
final class GislValidationError extends GislError
{
}
