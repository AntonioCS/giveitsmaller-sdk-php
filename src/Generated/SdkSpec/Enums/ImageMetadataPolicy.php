<?php

/**
 * CODE GENERATED — DO NOT EDIT.
 * Source: compression_contracts/sdk-spec/ (see sdk-spec/README.md).
 * Regenerate with: scripts/generate.py.
 */

declare(strict_types=1);

namespace Gisl\Sdk\Generated\SdkSpec\Enums;

/**
 * Image metadata handling (compress.image, optimiser/same_format route):
 *   All  = strip all EXIF/IPTC/XMP (default, smallest file)
 *   Keep = preserve EXIF/ICC/XMP — worker-proven on the libcaesium formats
 *          (JPEG/PNG/WebP/GIF/TIFF; lambdas PR #260, un-parked 2026-06-23).
 * Keep is NOT available for AVIF (ravif) / SVG (SVGO) — those re-encode from
 * pixels and cannot preserve metadata, so the worker rejects it there (the
 * `metadata` enum is narrowed to [all] on those groups).
 */
enum ImageMetadataPolicy: string
{
    case All = 'all';
    case Keep = 'keep';
}
