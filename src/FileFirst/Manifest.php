<?php

declare(strict_types=1);

namespace Gisl\Sdk\FileFirst;

/**
 * Return value of {@see RunResult::downloadTo()} — the local paths written,
 * in the SAME order as {@see RunResult::$artifacts} (stable, output-order).
 *
 * Mirrors the TS `Manifest` in `packages/typescript/src/file-first.ts`.
 */
final class Manifest
{
    /**
     * @param list<string> $paths Local filesystem paths written, in output order.
     */
    public function __construct(
        public readonly array $paths,
    ) {
    }

    /**
     * @return array{paths: list<string>}
     */
    public function toArray(): array
    {
        return ['paths' => $this->paths];
    }
}
