<?php

declare(strict_types=1);

namespace Gisl\Sdk;

/**
 * Wire-format constructors for `WorkflowSourcePayload` (the `source` field on
 * a {@see JobDefinitionPayload}).
 *
 * Mirrors the TS source factories at packages/typescript/src/types.ts:114-148
 * exactly — same wire shape (`type` + payload-specific fields), same field
 * names (snake_case), same field ordering.
 *
 * Returned shape is `array<string, mixed>` rather than typed objects because
 * the wire shape is the contract here. The receiver `JobDefinitionPayload`
 * constructor accepts the array verbatim and serialises it through the
 * request loop; introducing a typed source DTO would only matter if the SDK
 * grew rich source-side validation, which it doesn't (the server validates).
 */
final class Sources
{
    /**
     * `{ type: 'upload', file_id }` — source is a previously-uploaded file.
     *
     * @return array{type: string, file_id: string}
     */
    public static function upload(string $fileId): array
    {
        return ['type' => 'upload', 'file_id' => $fileId];
    }

    /**
     * `{ type: 'job_output', from, operation? }` — source is the output of an
     * upstream job in the same workflow. `$from` references the upstream
     * job's `id`; pass `$operation` when the upstream has multiple operations
     * and a specific one is wanted.
     *
     * @return array{type: string, from: string, operation?: string}
     */
    public static function jobOutput(string $from, ?string $operation = null): array
    {
        $payload = ['type' => 'job_output', 'from' => $from];
        if ($operation !== null) {
            $payload['operation'] = $operation;
        }
        return $payload;
    }

    /**
     * `{ type: 'external_import', external_source_id }` — source is a
     * one-shot external import token.
     *
     * @return array{type: string, external_source_id: string}
     */
    public static function externalImport(string $externalSourceId): array
    {
        return ['type' => 'external_import', 'external_source_id' => $externalSourceId];
    }

    /**
     * `{ type: 'connection', connection_id, path }` — source is a configured
     * external connection (e.g. customer's S3 bucket).
     *
     * @return array{type: string, connection_id: string, path: string}
     */
    public static function connection(string $connectionId, string $path): array
    {
        return ['type' => 'connection', 'connection_id' => $connectionId, 'path' => $path];
    }
}
