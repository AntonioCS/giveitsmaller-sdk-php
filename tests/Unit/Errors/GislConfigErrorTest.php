<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Errors;

use Gisl\Sdk\Errors\GislConfigError;
use Gisl\Sdk\Errors\GislFeatureRequiresAuthError;
use Gisl\Sdk\Errors\GislMissingCredentialsError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GislConfigError::class)]
final class GislConfigErrorTest extends TestCase
{
    public function testSingleArgConstructorBackwardCompatible(): void
    {
        $e = new GislConfigError('boom');

        $this->assertSame('boom', $e->getMessage());
        $this->assertNull($e->reason);
        $this->assertNull($e->conflictingFields);
        $this->assertNull($e->resolvedSnapshot);
        $this->assertNull($e->suggestion);
    }

    public function testMetadataBagPopulated(): void
    {
        $e = new GislConfigError(
            'bad combo',
            reason: 'invalid_combination',
            conflictingFields: ['targetSize', 'codec'],
            resolvedSnapshot: ['codec' => 'vp9'],
            suggestion: 'use h264',
        );

        $this->assertSame('bad combo', $e->getMessage());
        $this->assertSame('invalid_combination', $e->reason);
        $this->assertSame(['targetSize', 'codec'], $e->conflictingFields);
        $this->assertSame(['codec' => 'vp9'], $e->resolvedSnapshot);
        $this->assertSame('use h264', $e->suggestion);
    }

    public function testAccessorMethodsMirrorProperties(): void
    {
        $e = new GislConfigError(
            'm',
            reason: 'unknown_field',
            conflictingFields: ['bogus'],
            resolvedSnapshot: ['a' => 1],
            suggestion: 'hint',
        );

        $this->assertSame('unknown_field', $e->getReason());
        $this->assertSame(['bogus'], $e->getConflictingFields());
        $this->assertSame(['a' => 1], $e->getResolvedSnapshot());
        $this->assertSame('hint', $e->getSuggestion());
    }

    public function testFeatureRequiresAuthSubclassStillConstructs(): void
    {
        $e = new GislFeatureRequiresAuthError(operation: 'compress', message: 'needs auth');

        $this->assertInstanceOf(GislConfigError::class, $e);
        $this->assertSame('compress', $e->operation);
        $this->assertSame('needs auth', $e->getMessage());
        // Metadata defaults remain null for the legacy subclass path.
        $this->assertNull($e->reason);
        $this->assertNull($e->getReason());
    }

    public function testMissingCredentialsSubclassStillConstructs(): void
    {
        $e = new GislMissingCredentialsError('no key');

        $this->assertInstanceOf(GislConfigError::class, $e);
        $this->assertSame('no key', $e->getMessage());
        $this->assertNull($e->reason);
    }

    public function testExceptionChainingPreserved(): void
    {
        $cause = new \RuntimeException('underlying');
        $e = new GislConfigError('wrapper', reason: 'invalid_target_size', previous: $cause);

        $this->assertSame($cause, $e->getPrevious());
        $this->assertSame('invalid_target_size', $e->reason);
    }
}
