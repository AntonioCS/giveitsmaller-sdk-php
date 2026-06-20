<?php

/**
 * CODE GENERATED — DO NOT EDIT.
 * Source: compression_contracts/sdk-spec/ (see sdk-spec/README.md).
 * Regenerate with: scripts/generate.py.
 */

declare(strict_types=1);

namespace Gisl\Sdk\Generated\SdkSpec\Enums;

/**
 * Image metadata handling. Single value today (Option B, 2026-06-20):
 *   All = strip all EXIF/IPTC/XMP (smallest file)
 * The compress worker always strips metadata; None/Copyright/Sensitive were
 * removed — they never reached the worker (it hardcodes strip-everything), so
 * advertising metadata preservation was an over-claim. Preservation is a
 * possible future feature (would need worker support).
 */
enum ImageMetadataPolicy: string
{
    case All = 'all';
}
