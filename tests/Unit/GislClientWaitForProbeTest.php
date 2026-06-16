<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\Cancellation;
use Gisl\Sdk\Errors\GislAbortError;
use Gisl\Sdk\Errors\GislApiError;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\ProbeWaitOptions;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Unit coverage for {@see GislClient::waitForProbe()} (YOA6FpFr) — the bounded,
 * never-bounce upload-probe poll. Mirrors the TS `wait-for-probe.test.ts`.
 *
 * Wire contract (API-verified): 422 feature_not_available = not landed → poll;
 * any 200 = stop (any probe_status); 5xx = retry-then-give-up; timeout =
 * give-up. Never throws on those paths. Genuine errors (404) + cancellation throw.
 */
#[CoversClass(GislClient::class)]
final class GislClientWaitForProbeTest extends TestCase
{
    private const FID = '019539ab-1111-7000-8000-000000000001';

    private HttpFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new HttpFactory();
    }

    /**
     * @param list<ResponseInterface|\Throwable> $queue
     */
    private function makeClient(array $queue): GislClient
    {
        $http = new class ($queue) implements ClientInterface {
            /** @var list<ResponseInterface|\Throwable> */
            private array $queue;

            /** @param list<ResponseInterface|\Throwable> $queue */
            public function __construct(array $queue)
            {
                $this->queue = $queue;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $next = \array_shift($this->queue);
                if ($next === null) {
                    throw new \RuntimeException('Stub PSR-18 client: response queue exhausted');
                }
                if ($next instanceof \Throwable) {
                    throw $next;
                }
                return $next;
            }
        };

        return new GislClient(
            config: new GislClientConfig(baseUrl: 'https://api.example.com', apiKey: 'sk_test'),
            httpClient: $http,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
    }

    private function probeOk(string $probeStatus = 'ok'): ResponseInterface
    {
        $body = \json_encode([
            'success' => true,
            'data' => [
                'file_id' => self::FID,
                'probe_status' => $probeStatus,
                'media_metadata' => ['duration_seconds' => 600, 'codec' => 'h264', 'container' => 'mp4', 'probed_at' => '2026-06-16T10:00:00Z'],
                'processing_class_pre_assignment' => 'long_form',
            ],
        ], JSON_THROW_ON_ERROR);
        return new Response(200, ['Content-Type' => 'application/json'], $body);
    }

    /** @param array<string, string> $extraHeaders */
    private function notLanded(array $extraHeaders = []): ResponseInterface
    {
        $body = \json_encode([
            'success' => false,
            'error' => 'no probe result cached for this upload',
            'error_type' => 'feature_not_available',
            'violations' => [['feature' => 'upload.probe', 'availability' => 'planned']],
        ], JSON_THROW_ON_ERROR);
        return new Response(422, ['Content-Type' => 'application/json'] + $extraHeaders, $body);
    }

    private function proberCrash(): ResponseInterface
    {
        $body = \json_encode(['success' => false, 'error' => 'please retry', 'error_type' => 'internal_error'], JSON_THROW_ON_ERROR);
        return new Response(500, ['Content-Type' => 'application/json'], $body);
    }

    private function uploadNotFound(): ResponseInterface
    {
        $body = \json_encode(['success' => false, 'error' => 'upload not found', 'error_type' => 'upload_not_found'], JSON_THROW_ON_ERROR);
        return new Response(404, ['Content-Type' => 'application/json'], $body);
    }

    public function test_polls_past_422_then_stops_on_first_200(): void
    {
        $client = $this->makeClient([$this->notLanded(), $this->notLanded(), $this->probeOk()]);
        /** @var list<array{attempt:int, elapsedMs:int}> $polls */
        $polls = [];
        $result = $client->waitForProbe(self::FID, new ProbeWaitOptions(
            timeoutMs: 5000,
            onPoll: function (array $info) use (&$polls): void {
                $polls[] = $info;
            },
        ));

        self::assertTrue($result->landed);
        self::assertNotNull($result->probe);
        self::assertSame('ok', $result->probe->getProbeStatus());
        self::assertNull($result->reason);
        self::assertSame([1, 2, 3], \array_column($polls, 'attempt'));
    }

    public function test_stops_on_any_200_without_interpreting_probe_status(): void
    {
        $client = $this->makeClient([$this->probeOk('corrupt')]);
        $result = $client->waitForProbe(self::FID, new ProbeWaitOptions(timeoutMs: 5000));
        self::assertTrue($result->landed);
        self::assertNotNull($result->probe);
        self::assertSame('corrupt', $result->probe->getProbeStatus());
    }

    public function test_gives_up_with_timeout_when_probe_never_lands(): void
    {
        // Plenty of 422s queued; the tiny timeout trips give-up first. Never throws.
        $client = $this->makeClient(\array_fill(0, 50, $this->notLanded()));
        $result = $client->waitForProbe(self::FID, new ProbeWaitOptions(timeoutMs: 20));
        self::assertFalse($result->landed);
        self::assertSame('timeout', $result->reason);
        self::assertNull($result->probe);
    }

    public function test_retries_5xx_then_gives_up_with_prober_error(): void
    {
        $client = $this->makeClient([$this->proberCrash(), $this->proberCrash(), $this->proberCrash()]);
        $result = $client->waitForProbe(self::FID, new ProbeWaitOptions(timeoutMs: 5000));
        self::assertFalse($result->landed);
        self::assertSame('prober_error', $result->reason);
    }

    public function test_5xx_then_200_still_lands(): void
    {
        $client = $this->makeClient([$this->proberCrash(), $this->probeOk()]);
        $result = $client->waitForProbe(self::FID, new ProbeWaitOptions(timeoutMs: 5000));
        self::assertTrue($result->landed);
    }

    public function test_gives_up_on_repeated_transport_failures(): void
    {
        // A PSR-18 transport failure (ClientExceptionInterface) normalises to
        // GislNetworkError inside the client → transient → retry-then-give-up.
        $boom = static fn (): \Throwable => new class ('network down') extends \RuntimeException implements ClientExceptionInterface {
        };
        $client = $this->makeClient([$boom(), $boom(), $boom()]);
        $result = $client->waitForProbe(self::FID, new ProbeWaitOptions(timeoutMs: 5000));
        self::assertFalse($result->landed);
        self::assertSame('prober_error', $result->reason);
    }

    public function test_transport_failure_then_200_still_lands(): void
    {
        $boom = new class ('blip') extends \RuntimeException implements ClientExceptionInterface {
        };
        $client = $this->makeClient([$boom, $this->probeOk()]);
        $result = $client->waitForProbe(self::FID, new ProbeWaitOptions(timeoutMs: 5000));
        self::assertTrue($result->landed);
    }

    public function test_propagates_genuine_404_does_not_swallow(): void
    {
        $client = $this->makeClient([$this->uploadNotFound()]);
        $this->expectException(GislApiError::class);
        $client->waitForProbe(self::FID, new ProbeWaitOptions(timeoutMs: 5000));
    }

    public function test_honours_retry_after_but_clamps_to_remaining_budget(): void
    {
        // Retry-After 3600s but timeoutMs 30ms → must clamp + give up fast.
        $client = $this->makeClient(\array_fill(0, 5, $this->notLanded(['Retry-After' => '3600'])));
        $start = \microtime(true);
        $result = $client->waitForProbe(self::FID, new ProbeWaitOptions(timeoutMs: 30));
        self::assertFalse($result->landed);
        self::assertSame('timeout', $result->reason);
        self::assertLessThan(2.0, \microtime(true) - $start); // nowhere near 3600s
    }

    public function test_throws_abort_when_cancelled_before_start(): void
    {
        $cancellation = new Cancellation();
        $cancellation->cancel();
        $client = $this->makeClient([$this->probeOk()]);
        $this->expectException(GislAbortError::class);
        $client->waitForProbe(self::FID, new ProbeWaitOptions(cancellation: $cancellation));
    }

    public function test_throws_abort_when_cancelled_mid_wait(): void
    {
        $cancellation = new Cancellation();
        $client = $this->makeClient(\array_fill(0, 50, $this->notLanded()));
        $this->expectException(GislAbortError::class);
        // Cancel during the first poll's onPoll → the next loop-top check throws.
        $client->waitForProbe(self::FID, new ProbeWaitOptions(
            timeoutMs: 5000,
            onPoll: function (array $info) use ($cancellation): void {
                if ($info['attempt'] === 1) {
                    $cancellation->cancel();
                }
            },
            cancellation: $cancellation,
        ));
    }
}
