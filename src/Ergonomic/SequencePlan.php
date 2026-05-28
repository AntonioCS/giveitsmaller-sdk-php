<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

/**
 * @internal
 *
 * Resolved merge plan — the output of {@see MergeBuilder::planSequence()}
 * consumed by `uploadUniqueAssets()` + `buildPayload()`. NOT part of the
 * public API.
 */
final class SequencePlan
{
    /**
     * @param "video"|"audio"|"image"   $mediaKind
     * @param list<PositionEntry>       $positions    One per SEQUENCE position (repeats included).
     * @param array<string, Asset>      $uniqueAssets Asset-identity → Asset; one upload per entry.
     */
    public function __construct(
        public readonly string $mediaKind,
        public readonly array $positions,
        public readonly array $uniqueAssets,
    ) {
    }
}
