<?php

declare(strict_types=1);

namespace Gisl\Sdk\Preset;

use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\Generated\SdkSpec\Enums\PdfProfile;
use Gisl\Sdk\Generated\SdkSpec\Presets;

/**
 * PDF-compress preset leaf DTO — sparse delta. Mirrors the TS
 * `DocumentPdfCompressPresetOptions` (T4a). Field set (2): profile, grayscale
 * — the worker-honored stable PDF controls (contracts v2.96.0 Acrobat-PDF
 * realignment Lw1LseYr). colorspace / flatten_forms are `planned` (presets
 * never emit them); image_dpi / pages are per-call knobs, not preset cells.
 */
final class DocumentPdfCompressPresetOptions
{
    public function __construct(
        public readonly ?PdfProfile $profile = null,
        public readonly ?bool $grayscale = null,
    ) {
    }

    public static function shippedDefaultsFor(OptimizeFor $level): self
    {
        $cell = Presets::shippedDefaultsFor('document_pdf_compress', $level);

        return new self(
            profile: PresetCellTranslator::enum($cell, 'profile', PdfProfile::class),
            grayscale: PresetCellTranslator::bool($cell, 'grayscale'),
        );
    }
}
