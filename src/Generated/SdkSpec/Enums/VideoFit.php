<?php

/**
 * CODE GENERATED — DO NOT EDIT.
 * Source: compression_contracts/sdk-spec/ (see sdk-spec/README.md).
 * Regenerate with: scripts/generate.py.
 */

declare(strict_types=1);

namespace Gisl\Sdk\Generated\SdkSpec\Enums;

/**
 * Video resize mode. Only applies when width or height is set. Pad is video-only (image fit lacks pad).
 */
enum VideoFit: string
{
    case Max = 'max';
    case Crop = 'crop';
    case Scale = 'scale';
    case Pad = 'pad';
}
