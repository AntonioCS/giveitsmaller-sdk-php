<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

/**
 * A single S3 multipart part PUT failed terminally (after the configured
 * retry attempts) or could not be read. Extends `GislError` — NOT
 * `GislApiError` — because it carries no contract error envelope and is
 * thrown from the multipart upload path, never from the response handler.
 *
 * Mirrors `packages/typescript/src/errors.ts:GislMultipartPartError`.
 */
final class GislMultipartPartError extends GislError
{
    public function __construct(
        string $message,
        public readonly int $partNumber,
        public readonly string $uploadId,
    ) {
        parent::__construct($message);
    }
}
