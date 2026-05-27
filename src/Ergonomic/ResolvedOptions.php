<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

/**
 * Preset → resolved-options projection. P2 placeholder: `preset` is
 * always `null` until a future preset card ships the matrix. The shape
 * itself is normative per `docs/plans/sdk-ergonomics/plan.md` §11b.
 *
 * Mirrors the TS `ResolvedOptions` interface at
 * `packages/typescript/src/builder.ts:135-140`.
 */
final class ResolvedOptions
{
    /**
     * @param array<string, mixed> $applied   The options the SDK actually sent (pre-preset, today identity).
     * @param list<string>         $overrides Caller overrides (today always empty).
     * @param string               $presetVersion Schema-version tag for the preset matrix (today `'1.0'`).
     */
    public function __construct(
        public readonly ?string $preset,
        public readonly array $applied,
        public readonly array $overrides,
        public readonly string $presetVersion,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'preset' => $this->preset,
            'applied' => $this->applied,
            'overrides' => $this->overrides,
            'presetVersion' => $this->presetVersion,
        ];
    }
}
