<?php

/**
 * CODE GENERATED — DO NOT EDIT.
 * Source: compression_contracts/sdk-spec/ (see sdk-spec/README.md).
 * Regenerate with: scripts/generate.py.
 */

declare(strict_types=1);

namespace Gisl\Sdk\Generated\SdkSpec\Enums;

/**
 * Image compression algorithm. lossy = smaller file; lossless = no quality loss; auto = format-best.
 */
enum ImageMode: string
{
    case Lossy = 'lossy';
    case Lossless = 'lossless';
    case Auto = 'auto';
}
