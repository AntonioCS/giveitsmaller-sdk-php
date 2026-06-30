<?php

declare(strict_types=1);

namespace Gisl\Sdk\Preset;

use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\Generated\SdkSpec\Presets;

/**
 * EPUB-document-compress preset leaf DTO — sparse delta. Mirrors the TS
 * `DocumentEpubCompressPresetOptions` (T4a). Field set: fontSubsetting,
 * stripUnusedCss. No enum-typed fields.
 */
final class DocumentEpubCompressPresetOptions
{
    public function __construct(
        public readonly ?bool $fontSubsetting = null,
        public readonly ?bool $stripUnusedCss = null,
    ) {
    }

    public static function shippedDefaultsFor(OptimizeFor $level): self
    {
        $cell = Presets::shippedDefaultsFor('document_epub_compress', $level);

        return new self(
            fontSubsetting: PresetCellTranslator::bool($cell, 'fontSubsetting'),
            stripUnusedCss: PresetCellTranslator::bool($cell, 'stripUnusedCss'),
        );
    }
}
