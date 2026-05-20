<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

/**
 * 403 `MULTIPART_SESSION_AUTH_REQUIRED` — the multipart session was initiated
 * anonymously (no `manifest.userId`) and the SDK-3 resume-support endpoints
 * refuse to serve it on an authed caller. There is no "claim" workflow today
 * to bind an authed identity to an anonymously-started session; that is the
 * future flip tracked at upstream ticket 8LABloaz. Consumers hitting this on
 * resume should abandon and re-upload from scratch under the authed identity.
 *
 * Mirrors `packages/typescript/src/errors.ts:GislMultipartSessionAuthRequiredError`.
 */
final class GislMultipartSessionAuthRequiredError extends GislApiError
{
}
