<?php

declare(strict_types=1);

namespace Gisl\Sdk\FileFirst;

/**
 * One step in a {@see Recipe}'s sequential chain — an operation kind plus the
 * ergonomic arguments captured for it. Steps are lowered to {@see \Gisl\Sdk\OperationDef}
 * at `Recipe::toWorkflowPayload()` time (compress steps run the preset resolver
 * then; convert/thumbnail/text_watermark steps carry wire-ready options).
 *
 * Immutable value object. The `$options` shape is per-op:
 *  - `compress`       — `['optimize' => OptimizeFor|null]` (resolved at lower-time).
 *  - `convert`        — `['output_format' => string]` (the contract convert key; the `format` shorthand is lowered to it).
 *  - `thumbnail`      — `['width'? => int, 'height'? => int]` (nulls already dropped).
 *  - `text_watermark` — `['text' => string]`.
 */
final class RecipeStep
{
    /**
     * @param 'compress'|'convert'|'thumbnail'|'text_watermark' $opType
     * @param array<string, mixed>                              $options
     */
    public function __construct(
        public readonly string $opType,
        public readonly array $options,
    ) {
    }
}
