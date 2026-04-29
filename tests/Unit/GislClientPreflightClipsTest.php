<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\GislClient;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\PreflightClipError;
use Gisl\Sdk\PreflightClipsResult;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Unit coverage for the client-side `preflightClips` aggregator. The
 * partitioning contract (mirrors TS `Promise.allSettled`) is the load-
 * bearing invariant — the whole call NEVER propagates a per-probe failure,
 * regardless of whether the failure is a server-side `probe_status:
 * rejected` envelope OR a transport-level throw.
 */
#[CoversClass(GislClient::class)]
final class GislClientPreflightClipsTest extends TestCase
{
    private HttpFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new HttpFactory();
    }

    /**
     * @param list<ResponseInterface|\Throwable> $queue
     * @param-out list<RequestInterface>          $captured
     */
    private function stubClient(array $queue, array &$captured = []): ClientInterface
    {
        $captured = [];
        return new class ($queue, $captured) implements ClientInterface {
            /** @var list<ResponseInterface|\Throwable> */
            private array $queue;
            /** @var list<RequestInterface> */
            private array $captured;

            /**
             * @param list<ResponseInterface|\Throwable> $queue
             * @param list<RequestInterface>             $captured
             */
            public function __construct(array $queue, array &$captured)
            {
                $this->queue = $queue;
                $this->captured = &$captured;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured[] = $request;
                $next = \array_shift($this->queue);
                if ($next === null) {
                    throw new \RuntimeException('Stub PSR-18 client: response queue exhausted');
                }
                if ($next instanceof \Throwable) {
                    if (!$next instanceof ClientExceptionInterface) {
                        throw new \LogicException(
                            'Queued throwables must implement ClientExceptionInterface; got ' . \get_class($next),
                        );
                    }
                    throw $next;
                }
                return $next;
            }
        };
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonResponse(int $status, array $body): ResponseInterface
    {
        $encoded = \json_encode($body, JSON_THROW_ON_ERROR);
        return new Response($status, ['Content-Type' => 'application/json'], $encoded);
    }

    /**
     * Build a `{ success: true, data: { file_id, probe_status } }` envelope.
     */
    private function probeOk(string $fileId, string $status = 'ok'): ResponseInterface
    {
        return $this->jsonResponse(200, [
            'success' => true,
            'data' => [
                'file_id' => $fileId,
                'probe_status' => $status,
            ],
        ]);
    }

    private function makeClient(ClientInterface $http, string $baseUrl = 'https://api.example.com'): GislClient
    {
        return new GislClient(
            config: new GislClientConfig(baseUrl: $baseUrl, apiKey: 'sk_test'),
            httpClient: $http,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
    }

    private const FILE_A = '01936fb2-0000-7000-8000-00000000000a';
    private const FILE_B = '01936fb2-0000-7000-8000-00000000000b';
    private const FILE_C = '01936fb2-0000-7000-8000-00000000000c';

    public function testPreflightAllOk(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->probeOk(self::FILE_A),
            $this->probeOk(self::FILE_B),
            $this->probeOk(self::FILE_C),
        ], $captured);

        $client = $this->makeClient($http);
        $result = $client->preflightClips([self::FILE_A, self::FILE_B, self::FILE_C]);

        self::assertInstanceOf(PreflightClipsResult::class, $result);
        self::assertCount(3, $result->ok);
        self::assertCount(0, $result->rejected);
        self::assertCount(0, $result->errors);
        self::assertCount(3, $captured);
    }

    public function testPreflightOneServerRejected(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->probeOk(self::FILE_A),
            $this->probeOk(self::FILE_B, 'corrupt'),
            $this->probeOk(self::FILE_C),
        ], $captured);

        $client = $this->makeClient($http);
        $result = $client->preflightClips([self::FILE_A, self::FILE_B, self::FILE_C]);

        self::assertCount(2, $result->ok);
        self::assertCount(1, $result->rejected);
        self::assertCount(0, $result->errors);
        self::assertSame(self::FILE_B, $result->rejected[0]->getFileId());
        self::assertSame('corrupt', $result->rejected[0]->getProbeStatus());
    }

    public function testPreflightOneNetworkError(): void
    {
        // PSR-18 transport failures bubble through `probeUpload` as
        // `GislNetworkError`. The aggregator catches `\Throwable` regardless,
        // so a stub `ClientExceptionInterface` exercises the same code path.
        $transportFailure = new class ('connection refused') extends \RuntimeException implements ClientExceptionInterface {};

        $captured = [];
        $http = $this->stubClient([
            $this->probeOk(self::FILE_A),
            $transportFailure,
            $this->probeOk(self::FILE_C),
        ], $captured);

        $client = $this->makeClient($http);

        // The whole call MUST NOT propagate the per-probe failure.
        $result = $client->preflightClips([self::FILE_A, self::FILE_B, self::FILE_C]);

        self::assertCount(2, $result->ok);
        self::assertCount(0, $result->rejected);
        self::assertCount(1, $result->errors);
        self::assertSame(self::FILE_B, $result->errors[0]->fileId);
        self::assertInstanceOf(PreflightClipError::class, $result->errors[0]);
        // The thrown error is the `GislNetworkError` wrapper (probeUpload's
        // `sendAndUnwrap` normalises transport failures to that subclass).
        self::assertInstanceOf(\Gisl\Sdk\Errors\GislNetworkError::class, $result->errors[0]->error);
    }

    public function testPreflightEmptyFileIdList(): void
    {
        $captured = [];
        $http = $this->stubClient([], $captured);

        $client = $this->makeClient($http);
        $result = $client->preflightClips([]);

        self::assertInstanceOf(PreflightClipsResult::class, $result);
        self::assertCount(0, $result->ok);
        self::assertCount(0, $result->rejected);
        self::assertCount(0, $result->errors);
        self::assertCount(0, $captured);
    }
}
