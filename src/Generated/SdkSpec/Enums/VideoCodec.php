<?php

/**
 * CODE GENERATED — DO NOT EDIT.
 * Source: compression_contracts/sdk-spec/ (see sdk-spec/README.md).
 * Regenerate with: scripts/generate.py.
 */

declare(strict_types=1);

namespace Gisl\Sdk\Generated\SdkSpec\Enums;

/**
 * Video codec. H264 = widest compatibility; H265/Av1 = better compression but slower.
 */
enum VideoCodec: string
{
    case H264 = 'h264';
    case H265 = 'h265';
    case Vp9 = 'vp9';
    case Av1 = 'av1';
}
