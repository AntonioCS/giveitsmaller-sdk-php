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
- **Skipped (PHP)**: 4 (all with rationale + follow-up card)
- **Failing**: 0

The full PHP suite (unit + parity) is green via `make project/sdk/php/check`.
The four skips below are the only PHP conformance divergences; none is a
functional defect in the hand-written SDK logic, and all are tracked for
reduction. The launch gate passes with these documented exceptions.

The four split into two buckets:

- **Bucket A — PHP-specific divergence from the TS canonical reference**
  (§1 octet-stream). TS does NOT skip this; a consumer comparing PHP↔TS wire
  output could observe the difference.
- **Bucket B — shared TS+PHP skip of an out-of-contract fixture**
  (§2 recommendedChunkSize). The TS reference runner skips the same three
  fixtures for the same reason (`packages/typescript/tests/parity/parity.test.ts`);
  the fixture pins a value below the contract chunk-range floor, so neither SDK
  accepts it.

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

### 2. Sub-floor chunk size — both SDKs enforce the contract chunk-range (3 fixtures, Bucket B)

Both SDKs enforce the contract chunk-range floor of **16 MiB (16777216 bytes)**
on `recommendedChunkSize` (raised 5 MiB → 16 MiB by CON-1 / contracts
`z4GDTUMx`, ADR-0011): PHP via the generated
`MultipartInitiateResponse::setRecommendedChunkSize` hydrate-time validator, TS
via the same contract chunk-range guard (`packages/typescript/src/client.ts:~1097`,
a previously-lax TS path closed in codex review). These fixtures intentionally
pin a small (~2 MB) `recommended_chunk_size` to keep the binary payload
compact — a value below the contract floor — so **both** runners reject it and
skip the same three fixtures. This is a deliberate shared divergence on an
out-of-contract fixture, **not** a generator bug. The happy-path multipart wire
shape is covered elsewhere (PHP unit tests; TS `client.test.ts` /
`upload-streaming.test.ts`) with contract-valid chunk sizes.

| Fixture | Reason |
|---|---|
| `upload_multipart` | Both SDKs reject the fixture's sub-16-MiB (~2 MB) recommendedChunkSize. |
| `upload_metadata_hint` | Same sub-floor value as `upload_multipart`. |
| `upload_boundary_multipart` | Same sub-floor value as `upload_multipart`. |

**Resolution:** none planned — the fixtures pin an out-of-contract value on
purpose to keep payloads compact, and both SDKs correctly reject it. A
conformant server never sends below the 16 MiB floor.

## How to remove a skip

1. The underlying issue is fixed (in the SDK source, the generator-compat
   layer, the contracts package, or the fixture).
2. Remove the fixture's entry from the `KNOWN_DIVERGENCES` const in
   [`ParityTest.php`](./ParityTest.php).
3. Remove the corresponding row from this file (and the status snapshot count).
4. Run `make project/sdk/php/check` and confirm the parity suite is green.
