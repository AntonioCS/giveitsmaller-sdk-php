<?php

/**
 * CODE GENERATED — DO NOT EDIT.
 * Source: compression_contracts/sdk-spec/ (see sdk-spec/README.md).
 * Regenerate with: scripts/generate.py.
 */

declare(strict_types=1);

namespace Gisl\Sdk\Generated\SdkSpec\Enums;

/**
 * PDF output color space.
 */
enum PdfColorspace: string
{
    case Unchanged = 'unchanged';
    case Rgb = 'rgb';
    case Cmyk = 'cmyk';
    case Grayscale = 'grayscale';
}
