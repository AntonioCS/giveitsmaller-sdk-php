<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

/**
 * @internal
 *
 * One resolved position in a merge sequence — the planner output consumed
 * by {@see MergeBuilder::buildPayload()}. NOT part of the public API.
 */
final class PositionEntry
{
    public function __construct(
        public readonly string $assetId,
        public readonly ClipOptions $options,
    ) {
    }
}
