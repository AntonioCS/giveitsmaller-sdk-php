<?php

/**
 * CODE GENERATED — DO NOT EDIT.
 * Source: compression_contracts/sdk-spec/ (see sdk-spec/README.md).
 * Regenerate with: scripts/generate.py.
 */

declare(strict_types=1);

namespace Gisl\Sdk\Generated\SdkSpec\Enums;

/**
 * Image metadata handling. Counter-intuitive wire naming:
 *   All       = strip everything (smallest file)
 *   None      = keep all EXIF/IPTC/XMP
 *   Copyright = keep only copyright/author fields
 *   Sensitive = keep EXIF but strip GPS/location (GDPR-friendly)
 */
enum ImageMetadataPolicy: string
{
    case All = 'all';
    case None = 'none';
    case Copyright = 'copyright';
    case Sensitive = 'sensitive';
}
