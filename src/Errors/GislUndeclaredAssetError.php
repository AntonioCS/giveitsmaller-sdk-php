<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

/**
 * `MergeBuilder::sequence(...)` referenced an asset that wasn't declared in
 * the prior `merge(...)` call. Local validation runs BEFORE upload so the
 * caller fails fast on the typo without burning bandwidth.
 *
 * Mirrors the TS reference `GislUndeclaredAssetError`
 * (`packages/typescript/src/errors.ts`).
 */
final class GislUndeclaredAssetError extends GislConfigError
{
    /**
     * @param string       $assetId        Identity of the undeclared reference.
     * @param list<string> $declaredAssets Identities declared in `merge(...)`.
     */
    public function __construct(
        public readonly string $assetId,
        public readonly array $declaredAssets,
    ) {
        parent::__construct(sprintf(
            "Sequence references asset '%s' but it wasn't declared in merge(...). "
            . 'Declared assets: [%s]. '
            . 'Either pass it to merge(...) before sequencing, or remove the reference.',
            $assetId,
            implode(', ', $declaredAssets),
        ));
    }
}
