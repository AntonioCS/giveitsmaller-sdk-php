<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Errors;

use Gisl\Sdk\Errors\GislBundleAlreadyArchivedError;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\Errors\GislError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Shape test for the double-bundle typed error landed in PHP P4d
 * (`hv3FpLjm`). Class is DORMANT today (exported, not thrown — mirrors TS) —
 * `.bundle()` (P4b `wpHoJhuo`) adds the firing site in a follow-up PR.
 */
#[CoversClass(GislBundleAlreadyArchivedError::class)]
final class GislBundleAlreadyArchivedErrorTest extends TestCase
{
    public function test_extends_gisl_config_error_hierarchy(): void
    {
        $err = new GislBundleAlreadyArchivedError();

        $this->assertInstanceOf(
            GislConfigError::class,
            $err,
            'Double-bundle is a client-side config violation — must satisfy GislConfigError catches.',
        );
        $this->assertInstanceOf(
            GislError::class,
            $err,
            'Future LocalError emitter filters on instanceof GislError; the typed error must satisfy it.',
        );
    }

    public function test_message_describes_double_bundle(): void
    {
        // Mirrors the TS message at packages/typescript/src/errors.ts — the
        // caller sees that the builder already produces an archive and that
        // `.bundle()` cannot be applied again.
        $message = (new GislBundleAlreadyArchivedError())->getMessage();

        $this->assertStringContainsString('already produces an archive', $message);
        $this->assertStringContainsString('.bundle()', $message);
    }
}
