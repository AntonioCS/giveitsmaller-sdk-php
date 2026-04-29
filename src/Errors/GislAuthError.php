<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

/**
 * 401 / authentication-specific failure.
 *
 * Mirrors `packages/typescript/src/errors.ts:GislAuthError` — extends
 * {@see GislApiError} (NOT {@see GislError} directly), so the typed-error tree
 * stays consistent with the TS reference and VOxtu0RZ-B2 can add further API
 * subclasses (balance / tier / feature) as siblings of GislAuthError without
 * forcing a re-parent.
 */
final class GislAuthError extends GislApiError
{
}
