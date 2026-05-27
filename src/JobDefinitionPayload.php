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
     * @param list<OperationDef>              $operations Ordered list of operations to apply.
     * @param array<string, mixed>|null       $source     Wire-format source payload built via {@see Sources}.
     * @param list<array<string, mixed>>|null $inputs     Multi-input job inputs — each element carries
     *                                                    `source` + optional `role` + optional
     *                                                    `per_input_options`.
     */
    public function __construct(
        public readonly array $operations,
        public readonly ?string $id = null,
        public readonly ?array $source = null,
        public readonly ?array $inputs = null,
        public readonly ?bool $deliver = null,
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
        return $payload;
    }
}
