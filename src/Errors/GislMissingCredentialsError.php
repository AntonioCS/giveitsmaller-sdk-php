<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

/**
 * Raised by {@see \Gisl\Sdk\Gisl::create()} before any HTTP I/O when the
 * credential chain (explicit arg, `GISL_API_KEY` env, `~/.gisl/credentials`
 * profile) produces no key AND the caller has not opted into cookie-mode
 * via `useSessionCookie: true`. Mirrors
 * `packages/typescript/src/errors.ts` `GislMissingCredentialsError`.
 *
 * Specialised subtype of {@see GislConfigError} (the SDK's umbrella
 * client-side configuration error class). Catching `GislConfigError`
 * therefore also catches this case; catching this specifically lets
 * callers distinguish "missing credentials — prompt the user" from
 * other configuration faults. Mirrors the TS hierarchy
 * (`packages/typescript/src/errors.ts:400`).
 */
final class GislMissingCredentialsError extends GislConfigError
{
}
