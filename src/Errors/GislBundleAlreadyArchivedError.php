<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

/**
 * Thrown by `.bundle(...)` when the target builder's terminal job is already an
 * `archive` op — double-bundle prevention (a builder that already produces an
 * archive cannot be bundled again). No HTTP: raised during lowering, before any
 * upload. Per the lowering spec
 * (`docs/plans/sdk-ergonomics/lowering.md:484`, id `bundle_already_archived_error`).
 *
 * Mirrors the TS reference `GislBundleAlreadyArchivedError`
 * (`packages/typescript/src/errors.ts`). Dormant until `.bundle()` ships
 * (wpHoJhuo) — the class lands here so that PR is a pure addition.
 */
final class GislBundleAlreadyArchivedError extends GislConfigError
{
    public function __construct()
    {
        parent::__construct(
            'This builder already produces an archive (bundle); .bundle() cannot '
            . 'be applied to an already-bundled builder.',
        );
    }
}
