<?php

declare(strict_types=1);

namespace Gisl\Sdk;

use Gisl\Generated\OpenApi\Model\OperationsSchemaResponse;

/**
 * 200-response variant of {@see GetSchemaResult}. Carries the typed schema
 * body plus the cache markers (`etag`, `lastModified`) the caller should
 * persist for the next conditional-revalidation request.
 *
 * `etag` / `lastModified` are nullable because the server is not contractually
 * required to emit them on every response — strong-ETag emission is best-
 * effort, and a missing `Last-Modified` header is valid (HTTP/1.1 §13.3).
 */
final class GetSchemaHitResult extends GetSchemaResult
{
    public function __construct(
        public readonly OperationsSchemaResponse $schema,
        public readonly ?string $etag = null,
        public readonly ?string $lastModified = null,
    ) {
    }
}
