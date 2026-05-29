<?php

declare(strict_types=1);

namespace Gisl\Sdk;

use Gisl\Sdk\Ergonomic\Asset;
use Gisl\Sdk\Ergonomic\Merge;
use Gisl\Sdk\Ergonomic\MergeBuilder;
use Gisl\Sdk\Ergonomic\MergeOptions;
use Gisl\Sdk\Ergonomic\OperationBuilder;
use Gisl\Sdk\Http\MultipartPartUploader;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Ergonomic-surface subclass of {@see GislClient}. Adds per-operation
 * factory methods that return an {@see OperationBuilder} ready for
 * `->run(...)` or `->submit(...)`. The low-level surface (`uploadFile`,
 * `createWorkflow`, etc.) is inherited verbatim — instances of this
 * class are full `GislClient` substitutes (LSP-safe; `instanceof
 * GislClient` continues to hold).
 *
 * Mirrors the TS Proxy at `packages/typescript/src/gisl.ts:102-133`
 * (`wrapErgonomic`). PHP has no Proxy primitive, so we use the
 * idiomatic equivalent: a subclass that adds the factory methods. See
 * the docblock on {@see GislClient} for the deliberate un-finalling
 * marker.
 *
 * Instantiation: call {@see Gisl::create()} — the inner factory
 * constructs a `GislErgonomicClient` (returned via the covariant
 * `Gisl::create(): GislErgonomicClient` signature). Direct construction
 * is supported for tests but the credential-chain resolution is the
 * ergonomic factory's job.
 */
class GislErgonomicClient extends GislClient
{
    /**
     * Mirrors the TS `wrapErgonomic` closure (`gisl.ts:116-136`) that captures
     * `presetDefaults` and injects it into each `new OperationBuilder(...)`.
     * The extra parameter is appended AFTER the inherited four so existing
     * positional (`Gisl::createInternal`) and named-argument (parity adapter)
     * construction keep working — it defaults to null. LSP holds:
     * `instanceof GislClient` is unaffected.
     */
    /**
     * Scoped preset defaults attached via {@see withPresetDefaults()} (P7 /
     * `5k3ZWo6B`). Layered between the client-default and per-call-override
     * layers in the resolver. NON-readonly because the immutable derive is a
     * clone-wither and PHP 8.1 forbids modifying a readonly property on a
     * clone; only {@see withPresetDefaults()} ever writes it, and only on the
     * freshly-cloned copy — `$this` is never mutated. (`sessionCookie` on the
     * parent {@see GislClient} is the existing non-readonly-property precedent.)
     */
    private ?PresetDefaults $scopedPresetDefaults;

    public function __construct(
        GislClientConfig $config,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        private readonly ?PresetDefaults $presetDefaults = null,
        ?PresetDefaults $scopedPresetDefaults = null,
        ?MultipartPartUploader $partUploader = null,
    ) {
        parent::__construct($config, $httpClient, $requestFactory, $streamFactory, $partUploader);
        $this->scopedPresetDefaults = $scopedPresetDefaults;
    }

    /**
     * Immutable scoped derive (P7 / `5k3ZWo6B`, TS T4c). Returns a NEW client
     * that resolves compress presets with `$defaults` layered on top of any
     * existing scoped defaults — `$defaults`' per-cell fields win, the prior
     * scope fills the gaps (see {@see PresetDefaults::merge()}). Chains:
     * `$client->withPresetDefaults($a)->withPresetDefaults($b)`.
     *
     * The derive is a `clone` of this client, so the underlying transport
     * (`httpClient`/`requestFactory`/`streamFactory`), `config`, credentials,
     * and the `sessionCookie` value are carried over BY the clone — there is
     * NO env/profile re-resolution and the parent is left completely unchanged
     * (concurrency-safe).
     *
     * **Divergence from the TS reference (intentional):** TS `withPresetDefaults`
     * returns a Proxy wrapping the SAME live `GislClient` target
     * (`packages/typescript/src/gisl.ts:141-156`), so a later `login()` on the
     * parent is observed by the derived client. PHP has no Proxy seam — this
     * subclass IS the client — so `clone` takes a derive-time SNAPSHOT of the
     * session-cookie state; a post-derive `login()`/`logout()` on the parent
     * (or child) does NOT propagate to the other. This satisfies the "preserve
     * session-cookie state exactly" contract at derive time and is harmless for
     * the documented "configure the next N jobs" use case.
     */
    public function withPresetDefaults(PresetDefaults $defaults): self
    {
        $derived = clone $this;
        $derived->scopedPresetDefaults = $this->scopedPresetDefaults === null
            ? $defaults
            : PresetDefaults::merge($this->scopedPresetDefaults, $defaults);

        return $derived;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function compress(string $input, array $options = []): OperationBuilder
    {
        return new OperationBuilder($this, 'compress', $input, $options, $this->presetDefaults, $this->scopedPresetDefaults);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function thumbnail(string $input, array $options = []): OperationBuilder
    {
        return new OperationBuilder($this, 'thumbnail', $input, $options, $this->presetDefaults, $this->scopedPresetDefaults);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function convert(string $input, array $options = []): OperationBuilder
    {
        return new OperationBuilder($this, 'convert', $input, $options, $this->presetDefaults, $this->scopedPresetDefaults);
    }

    /**
     * Multi-input merge compose. PHP P3 / dxIeLVbP. Mirrors the TS
     * reference `client.merge(...assets, options?)` at
     * `packages/typescript/src/gisl.ts:115-128` — PHP collapses the
     * variadic+last-arg-options TS idiom into an explicit array + named
     * options arg (matches the rest of the SDK's signature shape).
     *
     * Bare strings are auto-wrapped via {@see Merge::asset()}; pre-uploaded
     * file_ids enter the asset set via {@see Merge::handle()}. See the
     * {@see MergeBuilder} docblock for wire-truth boundaries per media kind.
     *
     * @param list<Asset|string> $assets Declared assets (paths or handles).
     *                                   At least 2 are required at run/submit;
     *                                   image/audio/video kind is inferred from
     *                                   the first asset's path unless
     *                                   {@see MergeOptions::$mediaKind} is set.
     */
    public function merge(array $assets, ?MergeOptions $options = null): MergeBuilder
    {
        $coerced = [];
        foreach ($assets as $a) {
            $coerced[] = $a instanceof Asset ? $a : Merge::asset($a);
        }
        return new MergeBuilder($this, $coerced, $options ?? new MergeOptions());
    }

    // `watermark()` and `archive()` factories are NOT shipped in P2/P3.
    //
    // - `watermark`: the v2 `OperationType` enum has NO bare `watermark`
    //   value — the contract split it into `image_watermark` /
    //   `text_watermark` / (planned) `audio_watermark`. A bare
    //   `OperationDef(type: 'watermark', ...)` would be rejected by the
    //   server with a validation error. Wiring this needs a preset-style
    //   mapping that picks the right sub-op for the given input MIME +
    //   options — tracked as a follow-up alongside the preset matrix
    //   (P5+ in the batch plan).
    //
    // - `archive`: the contract models `archive` as a MULTI-INPUT
    //   operation (`JobDefinitionPayload.inputs[]`), but the single-input
    //   `OperationBuilder` here always sends `source`. A correct archive
    //   factory needs a dedicated multi-input/bundle builder — that's
    //   exactly what P4 (.bundle archive sugar) ships.
    //
    // Both verbs stay on the {@see \Gisl\Sdk\Tests\Parity\Invoke}
    // ergonomic seam (NotYetImplementedDispatch) until those follow-ups
    // land. Codex review caught this gap in PHP P2 (7QXkzoIi) before
    // merge — the original plan listed five verbs but only three are
    // structurally implementable today.
}
