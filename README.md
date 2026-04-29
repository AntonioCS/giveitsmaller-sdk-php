# `giveitsmaller/sdk` — PHP SDK for GISL

Customer-facing PHP SDK for the GISL (Give It Smaller) file compression service.

> **Status:** v0.2 (sub-cards `VOxtu0RZ-A` + `VOxtu0RZ-B1`). Single-shot upload, sequential multipart upload (>10 MB), workflow create + status, workflow downloads, and webhook verification. SSE, polling helpers, and the rest of the method surface land in `VOxtu0RZ-B2` (`bf68ju2r`). Concurrent multipart for Guzzle / Symfony HttpClient is tracked separately as `lv43MVSl`.

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

// Single-shot status poll. (Polling helper `waitForWorkflow` arrives in
// VOxtu0RZ-B2.)
$status = $client->getWorkflowStatus($workflow->getWorkflowId());
echo $status->getStatus(); // 'pending' | 'in_progress' | 'completed' | ...

// Once the workflow finishes, fetch download URLs.
$downloads = $client->getWorkflowDownloads($workflow->getWorkflowId());
foreach ($downloads->getDownloads() as $job) {
    foreach ($job->getFiles() as $file) {
        echo $file->getUrl(), "\n";
    }
}
```

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
| `VOxtu0RZ-B1` (this) | Sequential multipart upload + webhook verification + workflow downloads |
| `VOxtu0RZ-B2` (`bf68ju2r`) | SSE consumer, remaining method surface, full error tree, parity runner |
| `lv43MVSl` | Concurrent multipart upload (Guzzle Pool / Symfony HttpClient) |
| `Wwcrdi73` | Packagist publish (mirror repo + auto-build) |

## License

MIT.
