<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

/**
 * A merge asset wrapping an already-uploaded `file_id`. Construct via
 * {@see Merge::handle()}. Use a handle when the SAME logical file should
 * be referenced from multiple merge runs (or multiple positions within
 * one merge) with guaranteed single-upload semantics — no path-sniffing
 * involved.
 *
 * Dedupe identity is the `file_id`. Two distinct `HandleAsset` instances
 * carrying the same `file_id` share one upload slot in the merge plan.
 */
final class HandleAsset implements Asset
{
    public function __construct(
        public readonly string $fileId,
    ) {
    }
}
