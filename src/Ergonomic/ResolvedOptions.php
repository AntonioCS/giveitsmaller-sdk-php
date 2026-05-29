<?php

declare(strict_types=1);

namespace Gisl\Sdk\Ergonomic;

/**
 * Preset → resolved-options projection. Normative shape per
 * `docs/plans/sdk-ergonomics/plan.md` §11b with the P6 (`koMKJjLY`, TS T4b)
 * extension: `sources` (per-layer field-name buckets) + optional
 * `presetConfigHash` (sha256 over the caller-side deltas, present iff any
 * non-SDK layer cell was registered).
 *
 * `overrides` is RETAINED for backward compat (the pre-P6 surface); it is
 * now a duplicate of `sources->explicit` and will be removed in a future
 * major. New code should read `sources` instead.
 *
 * Mirrors the TS `ResolvedOptions` interface at
 * `packages/typescript/src/builder.ts:227-248`.
 */
final class ResolvedOptions
{
    /**
     * @param array<string, mixed> $applied          The wire options the SDK actually sent.
     * @param list<string>         $overrides        Deprecated — mirror of `sources->explicit`.
     * @param string               $presetVersion    Schema-version tag for the preset matrix.
     * @param string|null          $presetConfigHash sha256 over `{clientDefault, scopedDefault,
     *                                                callPresetOverride}` canonical JSON, prefixed
     *                                                `sha256:`; null when only sdkDefault participated.
     */
    public function __construct(
        public readonly ?string $preset,
        public readonly array $applied,
        public readonly array $overrides,
        public readonly string $presetVersion,
        public readonly ResolvedOptionsSources $sources,
        public readonly ?string $presetConfigHash = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'preset' => $this->preset,
            'applied' => $this->applied,
            'overrides' => $this->overrides,
            'presetVersion' => $this->presetVersion,
            'sources' => $this->sources->toArray(),
        ];
        if ($this->presetConfigHash !== null) {
            $out['presetConfigHash'] = $this->presetConfigHash;
        }
        return $out;
    }
}
