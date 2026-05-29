<?php

/**
 * CODE GENERATED — DO NOT EDIT.
 * Source: compression_contracts/sdk-spec/ (see sdk-spec/README.md).
 * Regenerate with: scripts/generate.py.
 */

declare(strict_types=1);

namespace Gisl\Sdk\Generated\SdkSpec\Enums;

/**
 * ICC color profile handling.
 */
enum IccProfilePolicy: string
{
    case Preserve = 'preserve';
    case Strip = 'strip';
    case Srgb = 'srgb';
}
