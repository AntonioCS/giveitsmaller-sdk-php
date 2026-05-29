<?php

declare(strict_types=1);

namespace Gisl\Sdk\Preset;

use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\Generated\SdkSpec\Enums\PdfColorspace;
use Gisl\Sdk\Generated\SdkSpec\Enums\PdfProfile;
use Gisl\Sdk\Generated\SdkSpec\Presets;

/**
 * PDF-compress preset leaf DTO — sparse delta. Mirrors the TS
 * `DocumentPdfCompressPresetOptions` (T4a). Field set: profile,
 * colorspace, flattenForms.
 */
final class DocumentPdfCompressPresetOptions
{
    public function __construct(
        public readonly ?PdfProfile $profile = null,
        public readonly ?PdfColorspace $colorspace = null,
        public readonly ?bool $flattenForms = null,
    ) {
    }

    public static function shippedDefaultsFor(OptimizeFor $level): self
    {
        $cell = Presets::shippedDefaultsFor('document_pdf_compress', $level);

        return new self(
            profile: PresetCellTranslator::enum($cell, 'profile', PdfProfile::class),
            colorspace: PresetCellTranslator::enum($cell, 'colorspace', PdfColorspace::class),
            flattenForms: PresetCellTranslator::bool($cell, 'flattenForms'),
        );
    }
}
