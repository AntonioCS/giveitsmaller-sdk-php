<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

/**
 * `MergeBuilder::sequence(...)` on an image merge was given a `clip(ref, opts)`
 * entry. Image merges have NO per-input options in the wire today —
 * `transition` applies at the merge level and is uniform across all joins.
 *
 * Mirrors the TS reference `GislPerInputOptionsNotSupportedError`
 * (`packages/typescript/src/errors.ts`).
 */
final class GislPerInputOptionsNotSupportedError extends GislConfigError
{
    /**
     * @param string $mediaKind Merge media kind that lacks per-input options.
     */
    public function __construct(
        public readonly string $mediaKind,
    ) {
        parent::__construct(sprintf(
            '%s merge has no per-input options today; set \'transition\' at the '
            . '.merge(...) level instead — it applies to every join.',
            $mediaKind,
        ));
    }
}
