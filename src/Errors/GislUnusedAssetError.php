<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

/**
 * `MergeBuilder::sequence(...)` was called but at least one declared asset
 * wasn't referenced. Almost always a bug (wasted upload). Escape via
 * `MergeOptions(allowUnusedAssets: true)`.
 *
 * Mirrors the TS reference `GislUnusedAssetError`
 * (`packages/typescript/src/errors.ts`).
 */
final class GislUnusedAssetError extends GislConfigError
{
    /**
     * @param list<string> $unusedAssets Declared identities never sequenced.
     */
    public function __construct(
        public readonly array $unusedAssets,
    ) {
        parent::__construct(sprintf(
            'Assets [%s] were declared in merge(...) but never sequenced. '
            . 'Reference them in .sequence(...), remove them from the declaration, '
            . 'or pass {allowUnusedAssets: true} to opt out of this check.',
            implode(', ', $unusedAssets),
        ));
    }
}
