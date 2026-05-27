<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Ergonomic;

use Gisl\Sdk\Ergonomic\MaxWait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MaxWait::class)]
final class MaxWaitTest extends TestCase
{
    /**
     * @return array<string, array{string|int, int}>
     */
    public static function validInputs(): array
    {
        return [
            'int milliseconds' => [500, 500],
            'string bare ms' => ['500ms', 500],
            'string with whitespace' => ['  500ms  ', 500],
            'string uppercase suffix' => ['500MS', 500],
            'seconds' => ['120s', 120_000],
            'minutes' => ['30m', 1_800_000],
            'hours' => ['2h', 7_200_000],
            'no suffix defaults to ms' => ['750', 750],
            'fractional seconds' => ['0.5s', 500],
            'large int' => [86_400_000, 86_400_000],
        ];
    }

    #[DataProvider('validInputs')]
    public function test_parses_valid_values(int|string $input, int $expected): void
    {
        $this->assertSame($expected, MaxWait::parse($input));
    }

    /**
     * @return array<string, array{string|int}>
     */
    public static function invalidInputs(): array
    {
        return [
            'zero int' => [0],
            'negative int' => [-1],
            'empty string' => [''],
            'malformed string' => ['five minutes'],
            'unknown suffix' => ['5d'],
            'negative string' => ['-5s'],
            'just whitespace' => ['   '],
            'non-numeric prefix' => ['abc500ms'],
            // Codex r1 low b6895bed68cd — fractional sub-ms values used
            // to truncate to 0 and surface a misleading "deadline elapsed"
            // error. Now rejected upfront.
            'fractional ms truncates to zero' => ['0.5ms'],
            'fractional seconds truncates to zero' => ['0.0005s'],
            'fractional minutes truncates to zero' => ['0.00001m'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_rejects_invalid_values(int|string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MaxWait::parse($input);
    }

    public function test_constructor_is_private(): void
    {
        $reflection = new \ReflectionClass(MaxWait::class);
        $ctor = $reflection->getConstructor();
        $this->assertNotNull($ctor);
        $this->assertTrue($ctor->isPrivate(), 'MaxWait is a static helper; constructor must be private.');
    }
}
