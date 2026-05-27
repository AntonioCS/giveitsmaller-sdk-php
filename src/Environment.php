<?php

declare(strict_types=1);

namespace Gisl\Sdk;

/**
 * Named endpoint environments for {@see Gisl::create()}.
 *
 * Mirrors the TS string-union `Environment = 'prod' | 'staging'`
 * (`packages/typescript/src/credentials.ts:36`). A backed string enum is
 * the PHP-idiomatic equivalent and structurally prevents the
 * "unknown explicit environment" failure mode that the TS reference had
 * to enforce by hand (codex r2 medium 23a17c1dbf75) — call sites can
 * only construct a valid case, so a typo at the explicit-arg layer is
 * impossible. The `GISL_ENVIRONMENT` env-var path still accepts a raw
 * string and silently falls through on unknown values, matching TS.
 */
enum Environment: string
{
    case Prod = 'prod';
    case Staging = 'staging';
}
