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
 *   Strip = remove all EXIF/IPTC/XMP (default, smallest file) — the canonical
 *           strip token (renamed from `all` 2026-06-23; `all` reading as
 *           "strip all" was counterintuitive).
 *   Keep  = preserve EXIF/ICC/XMP — worker-proven on the libcaesium formats
 *           (JPEG/PNG/WebP/GIF/TIFF; lambdas PR #260, un-parked 2026-06-23).
 *   All   = DEPRECATED alias of Strip (still accepted on the wire, emits
 *           Deprecation/Sunset; the API lowers `strip`→`all` at the worker
 *           boundary). Migrate to Strip.
 * Keep is NOT available for AVIF (ravif) / SVG (SVGO) — those re-encode from
 * pixels and cannot preserve metadata, so the worker rejects it there (the
 * `metadata` enum is narrowed to [strip, all] on those groups).
 */
enum ImageMetadataPolicy: string
{
    case Strip = 'strip';
    case Keep = 'keep';
    case All = 'all';
}
