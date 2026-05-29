<?php

/**
 * CODE GENERATED — DO NOT EDIT.
 * Source: compression_contracts/sdk-spec/ (see sdk-spec/README.md).
 * Regenerate with: scripts/generate.py.
 */

declare(strict_types=1);

namespace Gisl\Sdk\Generated\SdkSpec\Enums;

/**
 * Encoding speed vs compression ratio trade-off (FFmpeg-style x264/x265 preset names).
 */
enum VideoPreset: string
{
    case Ultrafast = 'ultrafast';
    case Superfast = 'superfast';
    case Veryfast = 'veryfast';
    case Faster = 'faster';
    case Fast = 'fast';
    case Medium = 'medium';
    case Slow = 'slow';
    case Slower = 'slower';
    case Veryslow = 'veryslow';
}
