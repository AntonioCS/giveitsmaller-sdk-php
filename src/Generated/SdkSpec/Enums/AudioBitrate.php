<?php

/**
 * CODE GENERATED — DO NOT EDIT.
 * Source: compression_contracts/sdk-spec/ (see sdk-spec/README.md).
 * Regenerate with: scripts/generate.py.
 */

declare(strict_types=1);

namespace Gisl\Sdk\Generated\SdkSpec\Enums;

/**
 * Audio output bitrate in kbps. Shared between compress (audio + video.audioBitrate) cells.
 */
enum AudioBitrate: int
{
    case _64 = 64;
    case _96 = 96;
    case _128 = 128;
    case _192 = 192;
    case _256 = 256;
    case _320 = 320;
}
