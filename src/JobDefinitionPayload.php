<?php

declare(strict_types=1);

namespace Gisl\Sdk;

/**
 * One job inside a {@see WorkflowCreatePayload}.
 *
 * Mirrors `packages/typescript/src/types.ts:165-178` — a job sets either
 * `source` (single-input) or `inputs` (multi-input), never both. The SDK
 * does NOT enforce the XOR; the server validates and returns a typed
 * envelope error if violated.
 */
final class JobDefinitionPayload
{
    /**
     * @param list<OperationDef>              $operations       Ordered list of operations to apply.
     * @param array<string, mixed>|null       $source           Wire-format source payload built via {@see Sources}.
     * @param list<array<string, mixed>>|null $inputs           Multi-input job inputs — each element carries
     *                                                         `source` + optional `role` + optional
     *                                                         `per_input_options`.
     * @param bool|null                       $skipCompression  Per-job opt-out of the "compress required in every
     *                                                         chain" gate (ADR-0009 §D2). When `true`, the server
     *                                                         accepts a chain that doesn't terminate in `compress`
     *                                                         — required for chains observing multi-output fan-out
     *                                                         (e.g. convert PDF -> N images) without collapsing the
     *                                                         N outputs through a trailing chained compress.
     *                                                         Accepted by the API at
     *                                                         `compression/src/Jobs/.../JobDefinition.php`
     *                                                         (`skipCompression`) and validated against the
     *                                                         chain-ordering rule at `Job::validateChainOrdering`.
     *                                                         Undocumented in `contracts/openapi/api.yaml` —
     *                                                         spec follow-up pending.
     */
    public function __construct(
        public readonly array $operations,
        public readonly ?string $id = null,
        public readonly ?array $source = null,
        public readonly ?array $inputs = null,
        public readonly ?bool $deliver = null,
        public readonly ?bool $skipCompression = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        $payload = [];
        if ($this->id !== null) {
            $payload['id'] = $this->id;
        }
        if ($this->source !== null) {
            $payload['source'] = $this->source;
        }
        if ($this->inputs !== null) {
            $payload['inputs'] = $this->inputs;
        }
        $payload['operations'] = array_map(
            static fn (OperationDef $op): array => $op->toWire(),
            $this->operations,
        );
        if ($this->deliver !== null) {
            $payload['deliver'] = $this->deliver;
        }
        if ($this->skipCompression !== null) {
            $payload['skip_compression'] = $this->skipCompression;
        }
        return $payload;
    }
}
