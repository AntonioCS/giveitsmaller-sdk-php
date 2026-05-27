<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

/**
 * Parse a `maxWait` argument into wall-clock milliseconds.
 *
 * Mirrors `_parseMaxWait` in `packages/typescript/src/builder.ts:825`.
 * Accepts an `int` (already milliseconds) or a `string` with one of the
 * suffixes `ms` / `s` / `m` / `h` (defaulting to `ms` when omitted).
 *
 * Examples:
 *  - `MaxWait::parse(500)`     -> `500`
 *  - `MaxWait::parse('500ms')` -> `500`
 *  - `MaxWait::parse('120s')`  -> `120_000`
 *  - `MaxWait::parse('30m')`   -> `1_800_000`
 *  - `MaxWait::parse('2h')`    -> `7_200_000`
 *
 * Rejects non-positive numbers and malformed strings with
 * {@see \InvalidArgumentException}.
 */
final class MaxWait
{
    /**
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    public static function parse(int|string $value): int
    {
        if (\is_int($value)) {
            if ($value <= 0) {
                throw new \InvalidArgumentException(
                    "maxWait must be a positive integer (milliseconds); got {$value}.",
                );
            }
            return $value;
        }

        if (\preg_match('/^\s*(\d+(?:\.\d+)?)\s*(ms|s|m|h)?\s*$/i', $value, $match) !== 1) {
            throw new \InvalidArgumentException(
                "maxWait string must look like '500ms', '120s', '30m', '2h'; got '{$value}'.",
            );
        }

        $n = (float) $match[1];
        if ($n <= 0.0) {
            throw new \InvalidArgumentException(
                "maxWait must be > 0; got '{$value}'.",
            );
        }

        $unit = \strtolower($match[2] ?? 'ms');
        $scaled = match ($unit) {
            'ms' => $n,
            's' => $n * 1_000,
            'm' => $n * 60_000,
            'h' => $n * 3_600_000,
            // Unreachable: the regex's `(ms|s|m|h)?` alternation pins
            // $unit to one of those four (or empty -> 'ms' via ??).
            // PHPStan can't narrow the captured group's string union so
            // we keep the defensive arm.
            default => throw new \InvalidArgumentException("maxWait: unknown unit '{$unit}'."),
        };

        // Codex r1 low b6895bed68cd — fractional values that scale below
        // 1ms (e.g. `'0.5ms'`, `'0.0005s'`) would otherwise truncate to
        // 0 via `(int) $scaled` and then surface as the misleading
        // "Upload completed but maxWait elapsed" error from
        // OperationBuilder::run(). Reject upfront so the caller sees a
        // clear "must be >= 1ms" message.
        if ($scaled < 1.0) {
            throw new \InvalidArgumentException(
                "maxWait must be >= 1ms after unit scaling; '{$value}' resolved to {$scaled}ms.",
            );
        }
        return (int) $scaled;
    }
}
