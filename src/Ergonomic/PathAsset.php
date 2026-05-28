<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

/**
 * A merge asset declared by filesystem path. Construct via
 * {@see Merge::asset()} — passing a bare string to
 * {@see \Gisl\Sdk\GislErgonomicClient::merge()} auto-wraps to this.
 *
 * Dedupe identity is the EXACT string. Two distinct `PathAsset` instances
 * carrying the same path string upload ONCE per merge run (the merge
 * planner builds a string-keyed unique-asset map). Mirrors TS R2 medium
 * bb500566a683 — no trim, no trailing-slash strip — so the upload
 * identity always matches the dedupe identity.
 */
final class PathAsset implements Asset
{
    public function __construct(
        public readonly string $path,
    ) {
    }
}
