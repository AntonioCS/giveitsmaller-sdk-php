<?php

/**
 * CODE GENERATED — DO NOT EDIT.
 * Source: compression_contracts/sdk-spec/ (see sdk-spec/README.md).
 * Regenerate with: scripts/generate.py.
 */

declare(strict_types=1);

namespace Gisl\Sdk\Generated\SdkSpec\Enums;

/**
 * Audio codec. Aac = widest compatibility; Opus = best quality/size; Copy = passthrough (no re-encode).
 */
enum AudioCodec: string
{
    case Aac = 'aac';
    case Opus = 'opus';
    case Vorbis = 'vorbis';
    case Copy = 'copy';
}
