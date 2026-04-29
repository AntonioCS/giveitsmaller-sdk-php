<?php

declare(strict_types=1);

namespace Gisl\Sdk;

/**
 * One operation inside a {@see JobDefinitionPayload}.
 *
 * Mirrors the TS interface at packages/typescript/src/types.ts:73-76 — a
 * hand-written shape distinct from the generated `OperationDefinition` (which
 * exposes setters/getters and is the wire-receive type). This SDK-side input
 * type stays minimal so callers building requests get a clean named-arg
 * surface.
 */
final class OperationDef
{
    /**
     * @param string                $type    Operation kind: `compress`, `convert`, `merge`,
     *                                       `archive`, `thumbnail_image`, `thumbnail_video`,
     *                                       `thumbnail_document`, `thumbnail_office`,
     *                                       `image_watermark`, `text_watermark`,
     *                                       `audio_overlay`, `audio_watermark`,
     *                                       `custom_luma`. The server validates.
     * @param array<string, mixed>|null $options Operation-family-specific options. Snake_case
     *                                           wire keys (e.g. `quality`, `output_format`).
     */
    public function __construct(
        public readonly string $type,
        public readonly ?array $options = null,
    ) {
    }

    /**
     * @return array{type: string, options?: array<string, mixed>}
     */
    public function toWire(): array
    {
        $payload = ['type' => $this->type];
        if ($this->options !== null) {
            $payload['options'] = $this->options;
        }
        return $payload;
    }
}
