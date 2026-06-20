<?php

declare(strict_types=1);

namespace Gisl\Sdk\Preset;

use Gisl\Sdk\Generated\SdkSpec\Enums\ImageFormat;
use Gisl\Sdk\Generated\SdkSpec\Enums\ImageMetadataPolicy;
use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\Generated\SdkSpec\Presets;

/**
 * Image-compress preset leaf DTO — a sparse delta over the wire image
 * compress options. Every field is optional; an unset (null) field means
 * "fall through to the next layer" (shipped defaults / user delta /
 * per-call override), resolved by the P6 resolver.
 *
 * Mirrors the TS `ImageCompressPresetOptions` (T4a). Field set (3) per
 * contracts v2.80.0 compress.image honesty pass (Option B, lossy-only):
 * quality, metadata, outputFormat. `mode` + `iccProfile` were REMOVED — the
 * worker is lossy-only and always strips metadata, so advertising a lossless
 * mode or ICC policy was an over-claim. `progressive` is still a per-JPEG wire
 * option but is no longer carried in the preset cell / format-agnostic path.
 * `width`/`height`/`fit`/`autoOrient` were removed earlier (the image-compress
 * worker never resized — resize-fit lives on thumbnail/convert).
 */
final class ImageCompressPresetOptions
{
    public function __construct(
        public readonly ?int $quality = null,
        public readonly ?ImageMetadataPolicy $metadata = null,
        public readonly ?ImageFormat $outputFormat = null,
    ) {
    }

    /**
     * SDK shipped defaults for the (image, compress) cell at the given
     * level, read from the generated PRESETS matrix. Since the v2.80.0
     * honesty pass the worker is lossy-only, so every level ships a concrete
     * `quality` (Size 65 / Balanced 80 / Quality 92), `metadata: All`, and
     * `outputFormat: Original`.
     */
    public static function shippedDefaultsFor(OptimizeFor $level): self
    {
        $cell = Presets::shippedDefaultsFor('image_compress', $level);

        return new self(
            quality: PresetCellTranslator::int($cell, 'quality'),
            metadata: PresetCellTranslator::enum($cell, 'metadata', ImageMetadataPolicy::class),
            outputFormat: PresetCellTranslator::enum($cell, 'outputFormat', ImageFormat::class),
        );
    }
}
