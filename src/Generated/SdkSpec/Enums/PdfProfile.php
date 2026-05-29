<?php

/**
 * CODE GENERATED — DO NOT EDIT.
 * Source: compression_contracts/sdk-spec/ (see sdk-spec/README.md).
 * Regenerate with: scripts/generate.py.
 */

declare(strict_types=1);

namespace Gisl\Sdk\Generated\SdkSpec\Enums;

/**
 * PDF optimization profile. Web = aggressive; Print = preserve quality; Archive = PDF/A; Max = smallest.
 */
enum PdfProfile: string
{
    case Web = 'web';
    case Print = 'print';
    case Archive = 'archive';
    case Max = 'max';
}
