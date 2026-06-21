<?php

/**
 * CODE GENERATED — DO NOT EDIT.
 * Source: compression_contracts/sdk-spec/ (see sdk-spec/README.md).
 * Regenerate with: scripts/generate.py.
 */

declare(strict_types=1);

namespace Gisl\Sdk\Generated\SdkSpec\Enums;

/**
 * Image output format for compress. Original keeps the input format; Webp recompresses to WebP. Other format changes (jpeg/png/avif/...) are the convert operation's job, not compress.
 */
enum ImageFormat: string
{
    case Original = 'original';
    case Webp = 'webp';
}
