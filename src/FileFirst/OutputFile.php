<?php

declare(strict_types=1);

namespace Gisl\Sdk\FileFirst;

/**
 * A single deliverable output of a file-first run — the file-first layer's
 * flat output type.
 *
 * Distinct from (and coexisting with) the operation-first
 * {@see \Gisl\Sdk\Ergonomic\Artifact} until the operation-first layer is
 * removed (FF6). The file-first surface is deliberately leaner: just the
 * four fields a caller needs to identify + fetch an output.
 *
 * Mirrors the TS `OutputFile` in `packages/typescript/src/file-first.ts`.
 */
final class OutputFile
{
    public function __construct(
        public readonly string $url,
        public readonly string $filename,
        public readonly int $sizeBytes,
        public readonly string $operation,
    ) {
    }

    /**
     * Plain-array projection for tests + JSON-serialise paths. Field order
     * is fixed so the serialised shape matches the TS reference exactly
     * (cross-language shape assertion, FF1; harness parity fixture, FF2b).
     *
     * @return array{url: string, filename: string, sizeBytes: int, operation: string}
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'filename' => $this->filename,
            'sizeBytes' => $this->sizeBytes,
            'operation' => $this->operation,
        ];
    }
}
