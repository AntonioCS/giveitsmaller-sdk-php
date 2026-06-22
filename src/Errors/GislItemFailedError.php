<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

/**
 * A terminal item failure in {@see \Gisl\Sdk\FileFirst\RunResult::$failed} — an
 * input whose job did not reach `completed`. Stored in
 * {@see \Gisl\Sdk\FileFirst\ItemFailure::$error} so a caller can branch on the
 * failure reason WITHOUT string-parsing.
 *
 * - `$state`: the terminal lifecycle state (`failed` / `expired` / `cancelled` /
 *   `partially_failed` / `paused_insufficient_credits`, or a per-job
 *   non-`completed` status).
 * - `$errorMessage` / `$errorCode`: the human + machine fields read from the
 *   first failing operation (`OperationResponse.error_message` / `.error_code`).
 *   BOTH are null for non-`failed` terminal states — cancel / expire /
 *   credit-pause carry only the bare `$state`.
 *
 * The exception message is `$state`, optionally suffixed `: errorMessage`,
 * preserving the pre-typed string exactly (an empty-string `$errorMessage` still
 * adds the colon).
 *
 * Mirrors the TS `GislItemFailedError` in `packages/typescript/src/errors.ts`.
 */
final class GislItemFailedError extends GislError
{
    public function __construct(
        public readonly ?string $key,
        public readonly string $state,
        public readonly ?string $errorMessage = null,
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($state . ($errorMessage !== null ? ': ' . $errorMessage : ''));
    }
}
