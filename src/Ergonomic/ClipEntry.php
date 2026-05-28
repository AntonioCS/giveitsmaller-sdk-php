<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

/**
 * A sequence entry that carries per-position options. Construct via
 * {@see Merge::clip()}. The asset MUST already be declared in the parent
 * `merge(...)` call — undeclared refs raise
 * {@see \Gisl\Sdk\Errors\GislConfigError} at plan time.
 *
 * Mirrors the TS `ClipEntry` interface at
 * `packages/typescript/src/merge.ts:101-109`.
 */
final class ClipEntry
{
    public function __construct(
        public readonly Asset $asset,
        public readonly ClipOptions $options,
    ) {
    }
}
