<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

/**
 * Per-source field-name buckets surfaced on {@see ResolvedOptions::$sources}.
 * Each bucket lists the WIRE field names (snake_case) contributed by that
 * layer of the preset resolver. Buckets record WINNERS, not all
 * participants: a field that appears in a higher-precedence bucket was NOT
 * also left in a lower one.
 *
 * `scopedDefault` is reserved for P7 (`5k3ZWo6B` — `withPresetDefaults`
 * scoped derive). P6 always populates it as `[]`.
 *
 * Mirrors the TS `ResolvedOptionsSources` interface at
 * `packages/typescript/src/builder.ts:208-214`.
 */
final class ResolvedOptionsSources
{
    /**
     * @param list<string> $sdkDefault         Fields won by the SDK shipped preset cell.
     * @param list<string> $clientDefault      Fields won by `gisl(presetDefaults: ...)`.
     * @param list<string> $scopedDefault      Reserved for P7; always `[]` in P6.
     * @param list<string> $callPresetOverride Fields won by the per-call `presetOverrides` arg.
     * @param list<string> $explicit           Fields won by explicit per-call knobs.
     */
    public function __construct(
        public readonly array $sdkDefault,
        public readonly array $clientDefault,
        public readonly array $scopedDefault,
        public readonly array $callPresetOverride,
        public readonly array $explicit,
    ) {
    }

    /**
     * @return array<string, list<string>>
     */
    public function toArray(): array
    {
        return [
            'sdkDefault' => $this->sdkDefault,
            'clientDefault' => $this->clientDefault,
            'scopedDefault' => $this->scopedDefault,
            'callPresetOverride' => $this->callPresetOverride,
            'explicit' => $this->explicit,
        ];
    }

    /**
     * Empty buckets — the legacy/passthrough shape for non-resolver paths
     * (merge, non-compress ops, unknown media). Mirrors the TS placeholder
     * at `builder.ts:971-977`.
     */
    public static function empty(): self
    {
        return new self([], [], [], [], []);
    }
}
