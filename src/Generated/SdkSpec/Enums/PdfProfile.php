<?php

/**
 * CODE GENERATED — DO NOT EDIT.
 * Source: compression_contracts/sdk-spec/ (see sdk-spec/README.md).
 * Regenerate with: scripts/generate.py.
 */

declare(strict_types=1);

namespace Gisl\Sdk\Generated\SdkSpec\Enums;

/**
 * PDF Ghostscript preset. Screen = smallest; Ebook = mid; Printer = 300dpi; Prepress = print-production.
 */
enum PdfProfile: string
{
    case Screen = 'screen';
    case Ebook = 'ebook';
    case Printer = 'printer';
    case Prepress = 'prepress';
}
