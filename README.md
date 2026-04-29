# `giveitsmaller/sdk` — PHP SDK for GISL

Customer-facing PHP SDK for the GISL (Give It Smaller) file compression service.

> **Status:** v0.2 (sub-cards `VOxtu0RZ-A`, `VOxtu0RZ-B1`, `VOxtu0RZ-B2.3`). Single-shot upload, sequential multipart upload (>10 MB), workflow create + status, workflow downloads, webhook verification, plus the workflow lifecycle surface: `cancelWorkflow`, `resumeWorkflow`, `retryOperation`, `waitForWorkflow`, and `getMetadata`. SSE and the parity runner land in the remaining `VOxtu0RZ-B2` cards (`bf68ju2r`). Concurrent multipart for Guzzle / Symfony HttpClient is tracked separately as `lv43MVSl`.

## Install

The SDK code-targets PSR-18 / PSR-17 and resolves a concrete HTTP client at runtime via `php-http/discovery`. Install the SDK plus any PSR-18 implementation; Guzzle is the path of least resistance.

```bash
composer require giveitsmaller/sdk guzzlehttp/guzzle http-interop/http-factory-guzzle
```

> **Known transitive dep:** the `giveitsmaller/contracts` package this SDK depends on currently hard-requires `guzzlehttp/guzzle` ^7.3 in its own `composer.json`. That means even if you only want Symfony HttpClient, Composer still installs Guzzle through contracts. The SDK's *own* runtime code never imports Guzzle (only PSR-18 interfaces) — but the transitive footprint is real until the contracts generator template is fixed. Tracked: [7X0t2Cjr](https://trello.com/c/7X0t2Cjr).

## Quickstart

```php
use Gisl\Sdk\GislClient;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\WorkflowCreatePayload;
use Gisl\Sdk\JobDefinitionPayload;
use Gisl\Sdk\OperationDef;
use Gisl\Sdk\Sources;

$client = new GislClient(new GislClientConfig(
    baseUrl: 'https://api.giveitsmaller.com',
    apiKey: 'sk_...',
));

// Single-shot upload (files at-or-below 10 MB) or sequential multipart for
// larger files. The same call routes both — pass an `UploadOptions` to
// receive progress callbacks.
$upload = $client->uploadFile('/path/to/photo.jpg');

// Build and submit a compression workflow.
$workflow = $client->createWorkflow(new WorkflowCreatePayload(
    jobs: [
        new JobDefinitionPayload(
            operations: [new OperationDef(type: 'compress', options: ['quality' => 80])],
            id: 'compressed',
            source: Sources::upload($upload->getFileId()),
        ),
    ],
));

// Single-shot status poll OR `waitForWorkflow` to block until terminal.
$status = $client->getWorkflowStatus($workflow->getWorkflowId());
echo $status->getStatus(); // 'pending' | 'in_progress' | 'completed' | ...

// Block until the workflow reaches a terminal status. Defaults: 2 s poll
// interval, 5 min overall deadline. Pass a `WaitOptions` to override either
// or to wire an `onPoll` callback fired once per cycle.
use Gisl\Sdk\WaitOptions;

$terminal = $client->waitForWorkflow(
    $workflow->getWorkflowId(),
    new WaitOptions(
        intervalMs: 1_500,
        timeoutMs: 120_000,
        onPoll: fn (string $s) => error_log("workflow status: {$s}"),
    ),
);

// Once the workflow finishes, fetch download URLs.
$downloads = $client->getWorkflowDownloads($workflow->getWorkflowId());
foreach ($downloads->getDownloads() as $job) {
    foreach ($job->getFiles() as $file) {
        echo $file->getUrl(), "\n";
    }
}

// Lifecycle: cancel an in-flight workflow, resume one that paused on
// insufficient credits, retry a single failed operation by id, or fetch
// full upload metadata.
$client->cancelWorkflow($workflow->getWorkflowId());
$client->resumeWorkflow($workflow->getWorkflowId());
$client->retryOperation($operationId);
$metadata = $client->getMetadata($upload->getFileId());
```

## Streaming workflow events

`streamEvents($workflowId)` opens an SSE stream against `/api/workflows/{id}/events` and yields one `GislSseEvent` per parsed frame. The generator holds the connection open until the server closes the stream OR the caller `break`s out of the foreach.

```php
use Gisl\Sdk\GislSseEvent;

foreach ($client->streamEvents($workflow->getWorkflowId()) as $event) {
    /** @var GislSseEvent $event */
    match ($event->event) {
        'progress' => printf("  %d%% — %s\n", $event->data['percent'] ?? 0, $event->data['stage'] ?? ''),
        'complete' => printf("Done: %s\n", json_encode($event->data)),
        'error'    => printf("Error: %s\n", $event->data['message'] ?? 'unknown'),
        default    => null, // unknown event — ignore
    };

    if ($event->event === 'complete' || $event->event === 'error') {
        break; // caller-initiated close — connection is released by PHP's GC
    }
}
```

`GislSseEvent` carries exactly two readonly fields: `$event` (string, defaults to `"message"` if the server omits the `event:` field) and `$data` (the JSON-decoded payload as an associative array — snake_case keys preserved). `id:` and `retry:` SSE fields are deliberately not exposed: the SDK does not implement Last-Event-ID reconnection nor honour server-suggested retry intervals.

Frames whose `data:` body fails to JSON-decode are dropped silently rather than throwing — long-running consumers should not break on a single garbled frame. Non-2xx responses (e.g. 401 with an auth envelope) route through the same typed-error tree as the JSON endpoints, so `try { ... } catch (GislAuthError $e) { ... }` works for SSE just like `createWorkflow()`.

## Webhook verification

```php
use Gisl\Sdk\Webhook;
use Gisl\Sdk\Errors\GislError;

try {
    Webhook::verify(
        secret: $_ENV['GISL_WEBHOOK_SECRET'],   // returned by createWorkflow()
        signature: $request->getHeaderLine('X-GIS-Signature'),
        body: (string) $request->getBody(),     // RAW bytes — do NOT re-encode JSON
    );
} catch (GislError $e) {
    // Reject the webhook — body did not match the signature.
}
```

## Auth, credits, and contact

`VOxtu0RZ-B2.4` adds the auth surface and the credits / contact endpoints.

### Login / logout (cookie-credentialled SPAs)

```php
use Gisl\Generated\OpenApi\Model\LoginUserRequest;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\GislClientConfig;

// Cookie-auth flows MUST opt in via useSessionCookie: true. With it set,
// login() captures the gisl_session token from Set-Cookie and forwards it
// as Cookie: on every subsequent request from the same client instance.
$client = new GislClient(new GislClientConfig(
    baseUrl: 'https://api.giveitsmaller.com',
    useSessionCookie: true,
));

$session = $client->login(new LoginUserRequest([
    'email' => 'alice@example.com',
    'password' => '...',
]));
echo $session->getUser()->getEmail();

// Authenticated calls follow.
$balance = $client->getCreditsBalance();

// Idempotent: 401 "already logged out" is collapsed onto void.
$client->logout();
```

> **Threading note.** `useSessionCookie: true` makes the `GislClient`
> instance hold per-instance mutable state (the captured cookie). Sharing a
> single client across concurrent execution contexts — Swoole fibers,
> ReactPHP loops, parallel PHPUnit processes — is **unsupported** in that
> mode: a logout on context A clears the cookie observed by context B
> mid-request. Construct one client per fiber/worker for concurrent
> workloads. With `useSessionCookie: false` (the default), the client is
> stateless and safe to share.

`logout()` is idempotent — a 401 "already logged out" envelope is collapsed onto a void return so caller cleanup paths do not need to special-case the not-currently-authenticated branch. 5xx and network failures still throw.

### Contact form

```php
use Gisl\Generated\OpenApi\Model\ContactRequest;

$client->submitContact(new ContactRequest([
    'name' => 'Alice',
    'email' => 'alice@example.com',
    'subject' => 'general_enquiry', // ContactSubject enum value
    'message' => 'Hello, support team!',
    'website' => '', // honeypot — leave empty
]));
// Server returns 204 No Content on success; void.
```

Validation failures (missing email, non-empty honeypot) surface as `GislValidationError` with the typed `ValidationErrorEnvelope` payload.

### Credits balance and usage

```php
use Gisl\Sdk\CreditsUsageOptions;

// Snapshot of current credit position. Use this for spend-now affordances
// and tier-upgrade prompts — NOT the GislBalanceExhaustedError envelope
// (which only surfaces during workflow creation).
$balance = $client->getCreditsBalance();
echo $balance->getAvailableCredits();
echo $balance->getTier()->value; // UserTier enum

// Paginated transaction history. Server defaults: limit=20, offset=0.
$page = $client->getCreditsUsage(new CreditsUsageOptions(limit: 50, offset: 0));
foreach ($page->getTransactions() as $tx) {
    echo "{$tx->getCreatedAt()->format(DATE_RFC3339)}: {$tx->getDelta()}\n";
}
```

Both `/api/v2/credits/balance` and `/api/v2/credits/usage` live on the v2 API path — passing the wrong `baseUrl` (e.g. dropping the `/api/v2/` segment) is the most common configuration error.

## Typed errors

The SDK throws a typed exception tree mirroring `packages/typescript/src/errors.ts`. Catch the base `GislError` to handle any SDK failure; catch a subclass for narrowed handling.

| Class | When | Typed payload |
|---|---|---|
| `GislConfigError` | Client-side / SDK input validation (unreadable file, illegal config). Thrown before reaching the wire. | — |
| `GislNetworkError` | PSR-18 transport failure (DNS, TLS, mid-stream EOF). Wraps the underlying exception as `getPrevious()`. | — |
| `GislTimeoutError` | Per-request deadline elapsed (enforced by the injected PSR-18 client). | — |
| `GislApiError` | Generic 4xx / 5xx wire failure that doesn't match a typed branch below. | — |
| `GislAuthError` | 401 / 403 with a recognised auth `error_type`. Falls through to a typed-payload-less variant for legacy 401s. | `?AuthErrorResponse` |
| `GislValidationError` | 4xx with a list of `details[].message` entries (server-side validation envelope). | `ValidationErrorEnvelope` |
| `GislBalanceExhaustedError` | 402 + `error_type: balance_exhausted`. | `BalanceExhaustedResponse` |
| `GislTierRestrictedError` | 403 + `error_type: tier_restriction`. | `TierRestrictionResponse` |
| `GislFeatureTierRestrictedError` | 403 + `error_type: feature_tier_restricted`. | `FeatureTierRestrictedResponse` |
| `GislFeatureNotAvailableError` | 422 + `error_type: feature_not_available`. | `FeatureNotAvailableResponse` |
| `GislWorkflowExpiredError` | 422 + `error_type: workflow_expired`. | `WorkflowExpiredResponse` |

Every `GislApiError` subclass carries the I26 localisation triple (`messageKey`, `locale`, `messageParams`) for client-side i18n catalogs. `errorCode` is the wire-stable machine code from the `error` field — switch on this for control flow; never parse `getMessage()` (changes per locale).

```php
use Gisl\Sdk\Errors\GislBalanceExhaustedError;
use Gisl\Sdk\Errors\GislError;

try {
    $client->createWorkflow($payload);
} catch (GislBalanceExhaustedError $e) {
    // Typed payload exposes the contract-pinned shape directly.
    $action = $e->typedPayload->getRequiredAction();    // e.g. 'top_up'
    $links = $e->typedPayload->getLinks();              // upgrade / billing URLs
    redirectTo($links?->getTopUpUrl());
} catch (GislError $e) {
    // Catch-all for any other SDK-originated failure.
    log($e->getMessage());
}
```

If the server emits a malformed typed envelope (missing required field, etc.) the dispatch falls through to the generic `GislApiError` rather than handing back a half-constructed typed payload — defense-in-depth mirroring the TS reference.

## Multipart upload concurrency

`GislClientConfig::$multipartConcurrency` is recorded but currently **advisory** — multipart uploads in v0.x run sequentially (one chunk in flight at a time). This keeps the SDK abstraction PSR-18-compatible across every supported HTTP client. Concurrent multipart for shops on Guzzle or Symfony HttpClient is tracked separately as [lv43MVSl](https://trello.com/c/lv43MVSl) and will detect the concrete client at runtime, falling back to sequential for other PSR-18 implementations.

## Generated DTOs

Response types come from the `giveitsmaller/contracts` package, which is auto-generated from the OpenAPI spec. Access fields through getters (`->getFileId()`, `->getWorkflowId()`, `->getStatus()`, ...) — the generated DTOs declare their fields private. The SDK does NOT add a normalisation layer in this version.

## HTTP client injection

Want to use Symfony HttpClient or another PSR-18 implementation? Inject one explicitly and skip discovery:

```php
$client = new GislClient(
    config: $config,
    httpClient: $myPsr18Client,
    requestFactory: $myPsr17RequestFactory,
    streamFactory: $myPsr17StreamFactory,
);
```

## Local development

Inside the `giveitsmaller-sdks` monorepo, the package consumes `giveitsmaller/contracts` via a Composer path repository pointing at `../../generated/php`. The path repo entry is local-dev only and is stripped from the published Packagist tarball.

```bash
cd packages/php
composer install
composer test                 # PHPUnit
composer phpstan              # PHPStan level 8
composer check                # both
```

Or from the repo root:

```bash
make project/sdk/php/check
```

## Roadmap

| Card | Scope |
|---|---|
| `VOxtu0RZ-A` | Scaffold + single-shot upload + workflow create + status |
| `VOxtu0RZ-B1` | Sequential multipart upload + webhook verification + workflow downloads |
| `VOxtu0RZ-B2.3` (`lT54YsPS`, this) | Workflow lifecycle (cancel/resume/retry/wait) + getMetadata |
| `VOxtu0RZ-B2` (`bf68ju2r`) | SSE consumer + remaining method surface + parity runner |
| `lv43MVSl` | Concurrent multipart upload (Guzzle Pool / Symfony HttpClient) |
| `Wwcrdi73` | Packagist publish (mirror repo + auto-build) |

## License

MIT.
