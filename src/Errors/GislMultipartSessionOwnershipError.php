<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

/**
 * 403 `MULTIPART_SESSION_OWNERSHIP` — the caller is authenticated but the
 * multipart session belongs to a different user. Thrown by the SDK-3
 * resume-support endpoints. The session itself exists (otherwise the server
 * would return 404 NOT_FOUND); the caller's identity simply doesn't match
 * `manifest.userId`. Consumers should abandon the resume.
 *
 * Mirrors `packages/typescript/src/errors.ts:GislMultipartSessionOwnershipError`.
 */
final class GislMultipartSessionOwnershipError extends GislApiError
{
}
