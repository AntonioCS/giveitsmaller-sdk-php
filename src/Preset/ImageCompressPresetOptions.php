<?php

declare(strict_types=1);

namespace Gisl\Sdk\Preset;

use Gisl\Sdk\Generated\SdkSpec\Enums\IccProfilePolicy;
use Gisl\Sdk\Generated\SdkSpec\Enums\ImageFit;
use Gisl\Sdk\Generated\SdkSpec\Enums\ImageFormat;
use Gisl\Sdk\Generated\SdkSpec\Enums\ImageMetadataPolicy;
use Gisl\Sdk\Generated\SdkSpec\Enums\ImageMode;
use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\Generated\SdkSpec\Presets;

/**
 * Image-compress preset leaf DTO — a sparse delta over the wire image
 * compress options. Every field is optional; an unset (null) field means
 * "fall through to the next layer" (shipped defaults / user delta /
 * per-call override), resolved by the P6 resolver.
 *
 * Mirrors the TS `ImageCompressPresetOptions` (T4a). Field set per
 * VhIj4S7T: mode, quality, width, height, fit, metadata, iccProfile,
 * autoOrient, progressive, outputFormat.
 */
final class ImageCompressPresetOptions
{
    public function __construct(
        public readonly ?ImageMode $mode = null,
        public readonly ?int $quality = null,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly ?ImageFit $fit = null,
        public readonly ?ImageMetadataPolicy $metadata = null,
        public readonly ?IccProfilePolicy $iccProfile = null,
        public readonly ?bool $autoOrient = null,
        public readonly ?bool $progressive = null,
        public readonly ?ImageFormat $outputFormat = null,
    ) {
    }

    /**
     * SDK shipped defaults for the (image, compress) cell at the given
     * level, read from the generated PRESETS matrix. Fields absent from the
     * cell stay null (e.g. the Quality level omits `quality` because the
     * contract gates it on `mode: lossy`).
     */
    public static function shippedDefaultsFor(OptimizeFor $level): self
    {
        $cell = Presets::shippedDefaultsFor('image_compress', $level);

        return new self(
            mode: PresetCellTranslator::enum($cell, 'mode', ImageMode::class),
            quality: PresetCellTranslator::int($cell, 'quality'),
            width: PresetCellTranslator::int($cell, 'width'),
            height: PresetCellTranslator::int($cell, 'height'),
            fit: PresetCellTranslator::enum($cell, 'fit', ImageFit::class),
            metadata: PresetCellTranslator::enum($cell, 'metadata', ImageMetadataPolicy::class),
            iccProfile: PresetCellTranslator::enum($cell, 'iccProfile', IccProfilePolicy::class),
            autoOrient: PresetCellTranslator::bool($cell, 'autoOrient'),
            progressive: PresetCellTranslator::bool($cell, 'progressive'),
            outputFormat: PresetCellTranslator::enum($cell, 'outputFormat', ImageFormat::class),
        );
    }
}
