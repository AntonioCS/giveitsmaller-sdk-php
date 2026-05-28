<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Ergonomic;

use Gisl\Sdk\Ergonomic\Artifact;
use Gisl\Sdk\Ergonomic\MapEachBuilder;
use Gisl\Sdk\Ergonomic\OperationBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers {@see MapEachBuilder} aggregation + factory wiring. The full
 * end-to-end fan-out (parent run → fn → child run → combined Result)
 * cannot ship a clean unit test today: the KNOWN LIMITATION at
 * {@see MapEachBuilder} means `$art->url` cannot feed a real downstream
 * builder, and `OperationBuilder` is `final` so the parent can't be
 * stubbed without un-finalling. The reflection-targeted `aggregateStatus`
 * coverage here pins the codex r1 HIGH 88e7186edc9a regression
 * (previously the worst-of precedence ladder always returned
 * parent.status, masking failed children).
 */
#[CoversClass(MapEachBuilder::class)]
final class MapEachBuilderTest extends TestCase
{
    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function aggregateStatusProvider(): array
    {
        return [
            'all completed' => [['completed', 'completed', 'completed'], 'completed'],
            'failed wins over completed' => [['completed', 'failed'], 'failed'],
            'failed wins over expired' => [['failed', 'expired', 'completed'], 'failed'],
            'expired wins over paused' => [['expired', 'paused_insufficient_credits', 'completed'], 'expired'],
            'paused wins over cancelled' => [['paused_insufficient_credits', 'cancelled', 'completed'], 'paused_insufficient_credits'],
            'cancelled wins over partially_failed' => [['cancelled', 'partially_failed', 'completed'], 'cancelled'],
            'partially_failed wins over completed' => [['partially_failed', 'completed'], 'partially_failed'],
            'single completed' => [['completed'], 'completed'],
            // Unknown / empty inputs hit the defensive fall-through at
            // the end of aggregateStatus(); documented behaviour.
            'unknown statuses fall through to first' => [['weird_status', 'other_weird'], 'weird_status'],
        ];
    }

    /**
     * @param list<string> $statuses
     */
    #[DataProvider('aggregateStatusProvider')]
    public function test_aggregate_status_worst_of_precedence(array $statuses, string $expected): void
    {
        $method = (new \ReflectionClass(MapEachBuilder::class))->getMethod('aggregateStatus');
        $this->assertSame($expected, $method->invoke(null, $statuses));
    }

    public function test_aggregate_status_empty_returns_completed(): void
    {
        $method = (new \ReflectionClass(MapEachBuilder::class))->getMethod('aggregateStatus');
        // Empty status list -> falls through `??` to 'completed'. Pins
        // the no-children defensive default.
        $this->assertSame('completed', $method->invoke(null, []));
    }

    public function test_mapEach_factory_returns_MapEachBuilder(): void
    {
        // Build a real OperationBuilder by going through GislErgonomicClient
        // so we exercise the actual factory wiring. The HTTP client throws
        // on any request — we never reach transport in this test, just the
        // builder wrapping.
        $client = GislErgonomicClientFactoryTestHelper::client();
        $parent = $client->compress('/tmp/x', ['quality' => 80]);

        $mapEach = $parent->mapEach(static function (Artifact $_a): OperationBuilder {
            throw new \LogicException('unreachable in factory test');
        });

        $this->assertInstanceOf(MapEachBuilder::class, $mapEach);
    }

    public function test_mapEach_constructor_rejects_non_callable_fn(): void
    {
        // P4 (OMuSCt7y) re-scope — mapEach is no longer parked in the
        // ERGONOMIC_METHODS short-circuit; the scaffold is the public
        // surface today. Pins the constructor guard: a non-callable fn
        // must fail at construction, BEFORE any orchestration begins.
        $client = GislErgonomicClientFactoryTestHelper::client();
        $parent = $client->compress('/tmp/x', ['quality' => 80]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('MapEachBuilder fn must be callable');

        // @phpstan-ignore-next-line — deliberately passing a non-callable to verify the guard.
        new MapEachBuilder($parent, 'not-a-callable-value');
    }
}
