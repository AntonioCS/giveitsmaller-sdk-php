<?php

/**
 * CODE GENERATED — DO NOT EDIT.
 * Source: compression_contracts/sdk-spec/ (see sdk-spec/README.md).
 * Regenerate with: scripts/generate.py.
 */

declare(strict_types=1);

namespace Gisl\Sdk\Generated\SdkSpec\Enums;

/**
 * Image output format selection. Original keeps input; Auto picks best per browser support; Smallest tries all and returns smallest.
 */
enum ImageFormat: string
{
    case Original = 'original';
    case Auto = 'auto';
    case Smallest = 'smallest';
    case Jpeg = 'jpeg';
    case Png = 'png';
    case Webp = 'webp';
    case Avif = 'avif';
}
