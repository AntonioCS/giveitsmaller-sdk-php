<?php

declare(strict_types=1);

namespace Gisl\Sdk\Preset;

use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\Generated\SdkSpec\Presets;

/**
 * Office-document-compress preset leaf DTO — sparse delta. Mirrors the TS
 * `DocumentOfficeCompressPresetOptions` (T4a). Field set: imageQuality,
 * stripMacros, stripHiddenData, stripUnusedFonts. No enum-typed fields.
 */
final class DocumentOfficeCompressPresetOptions
{
    public function __construct(
        public readonly ?int $imageQuality = null,
        public readonly ?bool $stripMacros = null,
        public readonly ?bool $stripHiddenData = null,
        public readonly ?bool $stripUnusedFonts = null,
    ) {
    }

    public static function shippedDefaultsFor(OptimizeFor $level): self
    {
        $cell = Presets::shippedDefaultsFor('document_office_compress', $level);

        return new self(
            imageQuality: PresetCellTranslator::int($cell, 'imageQuality'),
            stripMacros: PresetCellTranslator::bool($cell, 'stripMacros'),
            stripHiddenData: PresetCellTranslator::bool($cell, 'stripHiddenData'),
            stripUnusedFonts: PresetCellTranslator::bool($cell, 'stripUnusedFonts'),
        );
    }
}
