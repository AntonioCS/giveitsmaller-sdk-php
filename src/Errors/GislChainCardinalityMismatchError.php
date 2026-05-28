<?php

declare(strict_types=1);

namespace Gisl\Sdk\Errors;

/**
 * Raised when a chain step would consume the previous step's output but
 * the previous step produces multiple artifacts (PDF → N pages, future
 * multi-output ops) and the caller did not opt into explicit fan-out via
 * `.mapEach(...)`. Mirrors the TS reference at
 * `packages/typescript/src/errors.ts:484-503`.
 *
 * **Dormant in PHP P4 (`OMuSCt7y`)** — same as TS T6 (`aDR1jnyZ`). The
 * class + audit-gate registration land here so the future chain-method
 * implementation (e.g. `convert($pdf, pages: '1-N')->compress(...)` —
 * not yet supported on the PHP builder surface, blocked by the
 * artifact-as-input KNOWN LIMITATION in `MapEachBuilder.php`) is a pure
 * addition with no public-API churn.
 *
 * NOT thrown by `.bundle()` — `.bundle()`-specific misuse (single-output
 * cannot satisfy `archive` `min_inputs: 2`; double-archive) uses its own
 * typed error per the normative lowering spec at
 * `docs/plans/sdk-ergonomics/lowering.md:470-475` (tracked in follow-up
 * card `hv3FpLjm`).
 */
final class GislChainCardinalityMismatchError extends GislConfigError
{
    public function __construct(
        public readonly string $previousOperation,
        public readonly string $attemptedOperation,
    ) {
        parent::__construct(
            sprintf(
                'Previous step (%s) produces multiple artifacts; '
                . 'use .mapEach(art => art.%s(...)) to apply the chain per-artifact, '
                . 'or branch to a single artifact first.',
                $previousOperation,
                $attemptedOperation,
            ),
        );
    }
}
