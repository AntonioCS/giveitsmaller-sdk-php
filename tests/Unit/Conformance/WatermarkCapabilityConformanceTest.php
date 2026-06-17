<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Conformance;

use Gisl\Generated\Operations\ImageWatermarkMetadata;
use Gisl\Sdk\FileFirst\WatermarkGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Watermark capability conformance guard (FF4a / Z7zTr789).
 *
 * The watermark planned-op gate reads a hand SDK table ({@see
 * WatermarkGate::CAPABILITY}) rather than the generated typed metadata, because
 * the typed `MimeGroupMetadata` carries NO supported-mime allowlist. The
 * allowlist + availability live only in the raw `availability.json` sidecar.
 * This suite pins the SDK table to that sidecar — a contract regen that changes
 * the supported mimes or availability of `image_watermark` / `video_watermark`
 * fails HERE. Mirrors the TS `watermark-capability-conformance.test.ts` (and the
 * wire-key-conformance pattern). The PHP test/check container ships only
 * `generated/php/`, located here via the contracts package install.
 */
#[CoversClass(WatermarkGate::class)]
final class WatermarkCapabilityConformanceTest extends TestCase
{
    /** @var array<string, mixed> */
    private static array $availability = [];

    public static function setUpBeforeClass(): void
    {
        // operations/src/ImageWatermarkMetadata.php -> dirname x3 = generated/php root.
        $opFile = (new \ReflectionClass(ImageWatermarkMetadata::class))->getFileName();
        $root = \dirname((string) $opFile, 3);
        $path = $root . '/availability/availability.json';
        $json = \json_decode((string) \file_get_contents($path), true);
        // Operations are nested under the top-level `operations` key.
        $ops = \is_array($json) && isset($json['operations']) && \is_array($json['operations'])
            ? $json['operations']
            : [];
        self::$availability = $ops;
    }

    /** Resolved availability: group-level, else op-level, else the 'stable' default. */
    private function resolvedAvailability(string $op, string $group): string
    {
        /** @var array<string, mixed> $opMeta */
        $opMeta = self::$availability[$op] ?? [];
        /** @var array<string, mixed> $groups */
        $groups = $opMeta['mime_groups'] ?? [];
        /** @var array<string, mixed> $groupMeta */
        $groupMeta = $groups[$group] ?? [];
        $avail = $groupMeta['availability'] ?? ($opMeta['availability'] ?? 'stable');

        return \is_string($avail) ? $avail : 'stable';
    }

    public function test_capability_table_matches_availability_json(): void
    {
        foreach (WatermarkGate::CAPABILITY as $op => $groups) {
            self::assertArrayHasKey($op, self::$availability, "{$op} is not a real contract operation");
            foreach ($groups as $group => $cell) {
                /** @var array<string, mixed> $opMeta */
                $opMeta = self::$availability[$op];
                /** @var array<string, mixed> $mimeGroups */
                $mimeGroups = $opMeta['mime_groups'] ?? [];
                self::assertArrayHasKey($group, $mimeGroups, "{$op}.{$group} missing in availability.json");
                /** @var array<string, mixed> $groupMeta */
                $groupMeta = $mimeGroups[$group];

                $contractMimes = $groupMeta['mimes'] ?? [];
                self::assertIsArray($contractMimes);
                $expected = $cell['mimes'];
                \sort($expected);
                \sort($contractMimes);
                self::assertSame($expected, $contractMimes, "{$op}.{$group} mimes drifted from the contract");

                self::assertSame(
                    $cell['availability'],
                    $this->resolvedAvailability($op, $group),
                    "{$op}.{$group} availability drifted from the contract",
                );
            }
        }
    }
}
