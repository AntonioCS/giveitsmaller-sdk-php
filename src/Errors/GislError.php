<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

/**
 * Base SDK error. All other Gisl* exceptions inherit from this.
 *
 * Mirrors `packages/typescript/src/errors.ts:10-25`. Catch this to handle any
 * SDK-originating failure; catch a subclass for typed handling.
 */
class GislError extends \RuntimeException
{
}
