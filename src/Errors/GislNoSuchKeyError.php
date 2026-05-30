<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

/**
 * Thrown by {@see \Gisl\Sdk\FileFirst\RunResult::byKey()} when no result
 * entry matches the requested key. A keyless run (no `key:` supplied to
 * `file()`) is addressable positionally only — `byKey()` always throws.
 *
 * Mirrors the TS `GislNoSuchKeyError` in `packages/typescript/src/errors.ts`.
 */
final class GislNoSuchKeyError extends GislError
{
}
