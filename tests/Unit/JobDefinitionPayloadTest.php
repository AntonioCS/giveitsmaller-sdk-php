<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\JobDefinitionPayload;
use Gisl\Sdk\OperationDef;
use Gisl\Sdk\Sources;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JobDefinitionPayload::class)]
final class JobDefinitionPayloadTest extends TestCase
{
    public function testToWireMinimalSingleInputJob(): void
    {
        $job = new JobDefinitionPayload(
            operations: [new OperationDef(type: 'compress', options: ['quality' => 80])],
            id: 'compressed',
            source: Sources::upload('file_a'),
        );

        self::assertSame(
            [
                'id' => 'compressed',
                'source' => ['type' => 'upload', 'file_id' => 'file_a'],
                'operations' => [
                    ['type' => 'compress', 'options' => ['quality' => 80]],
                ],
            ],
            $job->toWire(),
        );
    }

    public function testToWireMultiInputJobEmitsInputsArray(): void
    {
        $job = new JobDefinitionPayload(
            operations: [new OperationDef(type: 'archive', options: ['format' => 'zip'])],
            id: 'bundle',
            inputs: [
                ['source' => Sources::upload('file_a')],
                ['source' => Sources::upload('file_b')],
            ],
        );

        $wire = $job->toWire();
        self::assertArrayHasKey('inputs', $wire);
        self::assertCount(2, $wire['inputs']);
        self::assertArrayNotHasKey('source', $wire); // single-input field absent
        self::assertSame(['type' => 'upload', 'file_id' => 'file_a'], $wire['inputs'][0]['source']);
    }

    public function testToWireEmitsBothSourceAndInputsAndDeliverWhenSet(): void
    {
        // SDK does NOT enforce the source/inputs XOR — server validates.
        // Pin: when both are set on the payload, both land on the wire so
        // the server sees the (invalid) combination and returns its typed
        // error envelope. Regression check against accidental SDK-side
        // suppression of one field.
        $job = new JobDefinitionPayload(
            operations: [new OperationDef(type: 'compress')],
            id: 'broken',
            source: Sources::upload('file_a'),
            inputs: [['source' => Sources::upload('file_b')]],
            deliver: true,
        );

        $wire = $job->toWire();
        self::assertSame('broken', $wire['id']);
        self::assertSame(['type' => 'upload', 'file_id' => 'file_a'], $wire['source']);
        self::assertCount(1, $wire['inputs']);
        self::assertTrue($wire['deliver']);
    }

    public function testToWireOmitsNullOptionalsIndividually(): void
    {
        $job = new JobDefinitionPayload(
            operations: [new OperationDef(type: 'compress')],
        );

        $wire = $job->toWire();
        self::assertSame(['operations'], \array_keys($wire));
        self::assertSame(
            [['type' => 'compress']],
            $wire['operations'],
        );
    }

    public function testOperationDefToWireOmitsNullOptions(): void
    {
        // Sanity: nested OperationDef::toWire() is exercised via the
        // payload chain. This pins the no-options path.
        $op = new OperationDef(type: 'thumbnail');
        self::assertSame(['type' => 'thumbnail'], $op->toWire());

        $opWithOpts = new OperationDef(type: 'compress', options: ['quality' => 75]);
        self::assertSame(
            ['type' => 'compress', 'options' => ['quality' => 75]],
            $opWithOpts->toWire(),
        );
    }
}
