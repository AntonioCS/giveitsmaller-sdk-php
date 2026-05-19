<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

/**
 * The upload would require more than the S3 hard limit of 10 000 multipart
 * parts at the server-provided chunk size. Client-side guard (Model A: the
 * server computes the part plan; the SDK asserts the ceiling). Extends
 * `GislError` for the same reason as `GislMultipartPartError`.
 *
 * Mirrors `packages/typescript/src/errors.ts:GislMultipartPartCountError`.
 */
final class GislMultipartPartCountError extends GislError
{
    public function __construct(
        string $message,
        public readonly int $requiredParts,
        public readonly int $maxParts,
    ) {
        parent::__construct($message);
    }
}
