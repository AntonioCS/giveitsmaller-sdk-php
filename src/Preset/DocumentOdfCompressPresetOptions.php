<?php

declare(strict_types=1);

namespace Gisl\Sdk\Preset;

use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\Generated\SdkSpec\Presets;

/**
 * ODF-document-compress preset leaf DTO — sparse delta. Mirrors the TS
 * `DocumentOdfCompressPresetOptions` (T4a). Field set: stripMetadata,
 * stripUnusedStyles. No enum-typed fields.
 */
final class DocumentOdfCompressPresetOptions
{
    public function __construct(
        public readonly ?bool $stripMetadata = null,
        public readonly ?bool $stripUnusedStyles = null,
    ) {
    }

    public static function shippedDefaultsFor(OptimizeFor $level): self
    {
        $cell = Presets::shippedDefaultsFor('document_odf_compress', $level);

        return new self(
            stripMetadata: PresetCellTranslator::bool($cell, 'stripMetadata'),
            stripUnusedStyles: PresetCellTranslator::bool($cell, 'stripUnusedStyles'),
        );
    }
}
