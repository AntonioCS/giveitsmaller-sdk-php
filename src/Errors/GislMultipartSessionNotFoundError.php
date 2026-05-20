<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

/**
 * 404 `MULTIPART_SESSION_NOT_FOUND` — the durable multipart session referenced
 * by a resume / status / presign / keepalive call cannot be located (expired
 * past its 48h manifest TTL, deleted, or never existed). Thrown by the SDK-3
 * resume-support endpoints (`getUploadStatus`, `presignParts`,
 * `keepaliveUpload`, and the resume branch of `uploadFile`).
 *
 * Mirrors `packages/typescript/src/errors.ts:GislMultipartSessionNotFoundError`.
 * Carries no typed structured payload — the contract for the 3 resume-support
 * endpoints models this code as a plain `ErrorEnvelope`. Consumers should
 * detect via `instanceof` and abandon the resume; a fresh `uploadFile()` call
 * (without `resumeUploadId`) will start a new session.
 */
final class GislMultipartSessionNotFoundError extends GislApiError
{
}
