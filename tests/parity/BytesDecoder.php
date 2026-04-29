<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Parity;

/**
 * Decodes the fixture's `kind: bytes` markers (`source: inline | file | zeros`)
 * into raw byte strings.
 *
 * Mirrors `decodeBytesValue` in
 * `packages/typescript/tests/parity/fetch-stub.ts`. The 64MB cap on
 * `source: zeros` is identical so a malicious / malformed fixture can't
 * exhaust memory in either runner.
 */
final class BytesDecoder
{
    private const MAX_ZEROS_BYTES = 64 * 1024 * 1024;

    /**
     * @param array<string, mixed> $bytesValue Mapping with `kind: bytes`,
     *                                          `source: inline|file|zeros`,
     *                                          and a string `value`.
     * @param string|null $fixtureFile           Absolute path to the fixture
     *                                          for `source: file` resolution.
     */
    public static function decode(array $bytesValue, ?string $fixtureFile): string
    {
        if (($bytesValue['kind'] ?? null) !== 'bytes') {
            throw new \RuntimeException('BytesDecoder: expected kind=bytes');
        }
        $source = isset($bytesValue['source']) ? (string) $bytesValue['source'] : '';
        $value = isset($bytesValue['value']) ? (string) $bytesValue['value'] : '';

        switch ($source) {
            case 'inline':
                $decoded = \base64_decode($value, true);
                if ($decoded === false) {
                    throw new \RuntimeException('BytesDecoder: invalid base64 in inline value');
                }
                return $decoded;

            case 'zeros':
                if (\preg_match('/^\d+$/', $value) !== 1) {
                    throw new \RuntimeException(
                        "BytesDecoder: source=zeros value must be a non-negative integer, got \"{$value}\"",
                    );
                }
                $count = (int) $value;
                if ($count > self::MAX_ZEROS_BYTES) {
                    throw new \RuntimeException(
                        "BytesDecoder: source=zeros value {$count} exceeds cap " . self::MAX_ZEROS_BYTES,
                    );
                }
                return \str_repeat("\x00", $count);

            case 'file':
                if ($fixtureFile === null) {
                    throw new \RuntimeException(
                        'BytesDecoder: source=file requires the fixture path to resolve relative paths',
                    );
                }
                if (\str_starts_with($value, '/') || \preg_match('/^[A-Za-z]:[\\\\\/]/', $value) === 1) {
                    throw new \RuntimeException(
                        "BytesDecoder: source=file path must be relative; got \"{$value}\"",
                    );
                }
                $fixtureDir = \dirname($fixtureFile);
                $resolved = $fixtureDir . '/' . $value;
                $real = \realpath($resolved);
                $realDir = \realpath($fixtureDir);
                if ($real === false || $realDir === false) {
                    throw new \RuntimeException(
                        "BytesDecoder: source=file path \"{$value}\" did not resolve",
                    );
                }
                if (!\str_starts_with($real, $realDir . '/') && $real !== $realDir) {
                    throw new \RuntimeException(
                        "BytesDecoder: source=file resolves outside the fixture directory; got \"{$value}\"",
                    );
                }
                $bytes = \file_get_contents($real);
                if ($bytes === false) {
                    throw new \RuntimeException("BytesDecoder: failed to read \"{$real}\"");
                }
                return $bytes;
        }

        throw new \RuntimeException("BytesDecoder: unknown source \"{$source}\"");
    }
}
