<?php

/**
 * CODE GENERATED — DO NOT EDIT.
 * Source: compression_contracts/sdk-spec/ (see sdk-spec/README.md).
 * Regenerate with: scripts/generate.py.
 */

declare(strict_types=1);

namespace Gisl\Sdk\Generated\SdkSpec\Enums;

/**
 * Top-level preset selector. Resolved client-side to concrete wire fields per presets.yaml.
 */
enum OptimizeFor: string
{
    case Size = 'Size';
    case Balanced = 'Balanced';
    case Quality = 'Quality';
}
