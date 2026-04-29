<?php

declare(strict_types=1);

namespace Gisl\Sdk;

/**
 * 304-response variant of {@see GetSchemaResult}. Body is empty by
 * definition — the caller should keep using the cached schema they
 * previously received under the same `etag` / `lastModified` pair.
 *
 * Cache markers are echoed back from the response headers so callers can
 * keep a single bookkeeping path: write `etag` / `lastModified` from
 * whatever variant they received, regardless of whether the body was
 * present.
 */
final class GetSchemaNotModifiedResult extends GetSchemaResult
{
    public function __construct(
        public readonly ?string $etag = null,
        public readonly ?string $lastModified = null,
    ) {
    }
}
