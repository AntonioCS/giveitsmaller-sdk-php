<?php

/**
 * CODE GENERATED — DO NOT EDIT.
 * Source: compression_contracts/sdk-spec/ (see sdk-spec/README.md).
 * Regenerate with: scripts/generate.py.
 */

declare(strict_types=1);

namespace Gisl\Sdk\Generated\SdkSpec\Enums;

/**
 * Image resize mode. Only applies when width or height is set (depends_on width/height in compress.yaml).
 */
enum ImageFit: string
{
    case Max = 'max';
    case Crop = 'crop';
    case Scale = 'scale';
}
