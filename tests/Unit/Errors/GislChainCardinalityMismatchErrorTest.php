<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Errors;

use Gisl\Sdk\Errors\GislChainCardinalityMismatchError;
use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\Errors\GislError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Shape test for the chain-cardinality typed error landed in PHP P4
 * (`OMuSCt7y`). Class is DORMANT today (matches TS T6 — exported, not
 * thrown) — once the artifact-as-input chain-method API arrives (blocked
 * by the KNOWN LIMITATION on {@see \Gisl\Sdk\Ergonomic\MapEachBuilder}),
 * the firing site is added in a follow-up PR.
 */
#[CoversClass(GislChainCardinalityMismatchError::class)]
final class GislChainCardinalityMismatchErrorTest extends TestCase
{
    public function test_extends_gisl_config_error_hierarchy(): void
    {
        $err = new GislChainCardinalityMismatchError(
            previousOperation: 'convert',
            attemptedOperation: 'compress',
        );

        $this->assertInstanceOf(
            GislConfigError::class,
            $err,
            'Cardinality mismatch is a client-side config violation — must satisfy GislConfigError catches.',
        );
        $this->assertInstanceOf(
            GislError::class,
            $err,
            'Future LocalError emitter filters on instanceof GislError; the typed error must satisfy it.',
        );
    }

    public function test_constructor_exposes_readonly_operation_fields(): void
    {
        $err = new GislChainCardinalityMismatchError(
            previousOperation: 'convert',
            attemptedOperation: 'thumbnail',
        );

        $this->assertSame('convert', $err->previousOperation);
        $this->assertSame('thumbnail', $err->attemptedOperation);
    }

    public function test_message_names_previous_and_attempted_operations_and_suggests_mapEach(): void
    {
        // Mirrors the TS message format at
        // packages/typescript/src/errors.ts:494-502 — caller sees both
        // op names + the suggested `.mapEach(art => art.<attempted>(...))`
        // fix path. The message contract is parity-asserted across runners.
        $err = new GislChainCardinalityMismatchError(
            previousOperation: 'convert',
            attemptedOperation: 'compress',
        );

        $message = $err->getMessage();
        $this->assertStringContainsString('convert', $message);
        $this->assertStringContainsString('produces multiple artifacts', $message);
        $this->assertStringContainsString('.mapEach(art => art.compress(...))', $message);
        $this->assertStringContainsString('branch to a single artifact first', $message);
    }
}
