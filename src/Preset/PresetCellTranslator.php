<?php

declare(strict_types=1);

namespace Gisl\Sdk\Preset;

/**
 * Translate generated preset-cell values into typed leaf-DTO fields.
 *
 * The generated {@see \Gisl\Sdk\Generated\SdkSpec\Presets} matrix stores
 * enum-typed cell fields as their ergonomic-enum CANONICAL NAME (e.g.
 * `'Lossy'`, `'_96'`, `'H265'`) — NOT the wire backing value. The leaf
 * `*CompressPresetOptions` DTOs expose those fields as the generated enum
 * type, so the name must be resolved to the matching enum case here. The
 * wire backing value is produced later (by the P6 resolver via
 * `$case->value`), keeping a single source of truth for the preset values.
 *
 * Mirrors the TypeScript `_translate.ts` helper, adapted to PHP backed
 * enums: TS enum values ARE the wire union, so it returns the value; PHP
 * separates case from value, so this returns the case.
 */
final class PresetCellTranslator
{
    /**
     * Resolve an ergonomic-enum canonical NAME to its enum case. Throws on
     * an unknown name so generator/contract drift surfaces loudly rather
     * than shipping a bad literal downstream.
     *
     * @template T of \UnitEnum
     *
     * @param class-string<T> $enumClass
     *
     * @return T
     */
    public static function caseByName(string $enumClass, string $name): \UnitEnum
    {
        foreach ($enumClass::cases() as $case) {
            if ($case->name === $name) {
                return $case;
            }
        }
        $known = \implode(', ', \array_map(
            static fn (\UnitEnum $case): string => $case->name,
            $enumClass::cases(),
        ));
        throw new \InvalidArgumentException(
            "Preset enum translation: '{$name}' is not a case of {$enumClass}. Known cases: {$known}.",
        );
    }

    /**
     * Read an enum-typed cell field. Absent → null (sparse delta). Present
     * non-string is a generator invariant violation (enum fields are always
     * emitted as canonical-name strings).
     *
     * @template T of \UnitEnum
     *
     * @param array<string, string|int|bool> $cell
     * @param class-string<T>                $enumClass
     *
     * @return T|null
     */
    public static function enum(array $cell, string $key, string $enumClass): ?\UnitEnum
    {
        if (!\array_key_exists($key, $cell)) {
            return null;
        }
        $raw = $cell[$key];
        if (!\is_string($raw)) {
            throw new \LogicException(
                "Preset cell field '{$key}' expected an enum-name string, got " . \get_debug_type($raw) . '.',
            );
        }
        return self::caseByName($enumClass, $raw);
    }

    /**
     * Read an int cell field. Absent → null.
     *
     * @param array<string, string|int|bool> $cell
     */
    public static function int(array $cell, string $key): ?int
    {
        if (!\array_key_exists($key, $cell)) {
            return null;
        }
        $raw = $cell[$key];
        if (!\is_int($raw)) {
            throw new \LogicException(
                "Preset cell field '{$key}' expected int, got " . \get_debug_type($raw) . '.',
            );
        }
        return $raw;
    }

    /**
     * Read a bool cell field. Absent → null.
     *
     * @param array<string, string|int|bool> $cell
     */
    public static function bool(array $cell, string $key): ?bool
    {
        if (!\array_key_exists($key, $cell)) {
            return null;
        }
        $raw = $cell[$key];
        if (!\is_bool($raw)) {
            throw new \LogicException(
                "Preset cell field '{$key}' expected bool, got " . \get_debug_type($raw) . '.',
            );
        }
        return $raw;
    }

    private function __construct()
    {
    }
}
