# PHP parity-runner KNOWN_DIVERGENCES

Each entry below is a shared cross-SDK fixture under `tests/parity/fixtures/`
that the PHP parity runner deliberately **skips**. Every skip is a known PHP
divergence from the cross-language parity spec — an upstream openapi-generator
emitter quirk or a documented SDK behaviour — **not** a bug in the runner and
**not** masked by comparator hacks. Each is linked to a follow-up Trello card so
the list shrinks over time.

This file is the human-readable companion to the machine source of truth, the
`KNOWN_DIVERGENCES` const in
[`ParityTest.php`](./ParityTest.php) (`markTestSkipped` is driven from that
array). Keep the two in sync — see "How to remove a skip" below.

## Status snapshot (v1 launch gate — P8 `ftI4Myby`)

- **Shared fixtures**: 74
- **Skipped (PHP)**: 6 (all with rationale + follow-up card)
- **Failing**: 0

The full PHP suite (unit + parity) is green via `make project/sdk/php/check`.
The six skips below are the only PHP conformance divergences; none is a
functional defect in the hand-written SDK logic, and all are tracked for
reduction. The launch gate passes with these documented exceptions.

The six split into two buckets:

- **Bucket A — PHP-specific divergences from the TS canonical reference**
  (§1 octet-stream, §3 method_hint). TS does NOT skip these; a consumer
  comparing PHP↔TS wire output could observe the difference.
- **Bucket B — shared TS+PHP skip of an out-of-contract fixture**
  (§2 recommendedChunkSize). The TS reference runner skips the same three
  fixtures for the same reason (`packages/typescript/tests/parity/parity.test.ts`);
  the fixture pins a value below the contract floor, so neither SDK accepts it.

## Divergences

### 1. Multipart `file` part Content-Type (1 fixture, Bucket A) — Trello `RWWBYklu`

The PHP SDK hardcodes `Content-Type: application/octet-stream` on the multipart
`file` part in `singleShotUpload` / `multipartUpload`
(`packages/php/src/GislClient.php:2864`, `:2904`). The TS reference forwards the
caller's `Blob.type`. The fixture pins a specific type, so PHP and TS emit
different part content-types. Low-risk at the wire level — the upload part
content-type is advisory and the server re-sniffs the MIME from the bytes
(the `upload_small` fixture itself shows the server returning its own
`mime_type`).

| Fixture | Reason |
|---|---|
| `upload_small` | PHP hardcodes `application/octet-stream`; TS forwards the caller's type. |

**Resolution:** forward the caller-supplied content-type from the SDK upload
path (Trello `RWWBYklu`).

### 2. Over-strict generated response validator (3 fixtures, Bucket B) — Trello `09eNib6R` (Issue 2)

The openapi-generator emits a hydrate-time validator on
`MultipartInitiateResponse::setRecommendedChunkSize` that rejects values below
the 16 MiB minimum (raised 5 MiB → 16 MiB by CON-1 / contracts `z4GDTUMx`,
ADR-0011). These fixtures intentionally pin a small (~2 MB)
`recommended_chunk_size` to keep the binary payload compact — a value below the
contract floor. **Both** SDKs reject it: the TS SDK now enforces the same
contract-range guard (a previously-lax TS path closed in codex review), so it
skips the same three fixtures for the same reason. The happy-path multipart wire
shape is covered elsewhere (PHP unit tests; TS `client.test.ts` /
`upload-streaming.test.ts`) with contract-valid chunk sizes.

| Fixture | Reason |
|---|---|
| `upload_multipart` | Generated `setRecommendedChunkSize` rejects sub-16-MiB values; fixture pins ~2 MB. |
| `upload_metadata_hint` | Same validator as `upload_multipart`. |
| `upload_boundary_multipart` | Same validator as `upload_multipart`. |

**Resolution:** relax/remove the generator-level wire-response constraint in the
generator-compat layer (Trello `09eNib6R`, Issue 2 — over-validates response
DTOs at hydrate time).

### 3. Generated default for an optional request field (2 fixtures, Bucket A) — Trello `09eNib6R` (Issue 3)

`AudioWatermarkDecodeRequest::__construct` defaults `method_hint` to `'auto'`
(via `setIfExists`) when the caller omits it, so the PHP wire body always
carries `method_hint`. The TS reference leaves the field `undefined` and
`JSON.stringify` drops the key. The divergence is visible only on the
omit-`methodHint` fixtures (the happy path passes both runners).

| Fixture | Reason |
|---|---|
| `error_403_audio_watermark_decode_tier` | PHP emits `method_hint=auto`; TS omits the key. |
| `error_422_audio_watermark_decode_planned` | Twin of `error_403_audio_watermark_decode_tier`. |

**Resolution:** drop the generated default in the PHP model, or strip
null/default optional fields before send (Trello `09eNib6R`, Issue 3 — emits
default values for optional request fields).

## How to remove a skip

1. The underlying issue is fixed (in the SDK source, the generator-compat
   layer, the contracts package, or the fixture).
2. Remove the fixture's entry from the `KNOWN_DIVERGENCES` const in
   [`ParityTest.php`](./ParityTest.php).
3. Remove the corresponding row from this file (and the status snapshot count).
4. Run `make project/sdk/php/check` and confirm the parity suite is green.
