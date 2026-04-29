<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Generated\OpenApi\Model\CreditsBalanceResponse;
use Gisl\Generated\OpenApi\Model\CreditsUsageResponse;
use Gisl\Sdk\CreditsUsageOptions;
use Gisl\Sdk\GislClient;
use Gisl\Sdk\GislClientConfig;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Unit coverage for VOxtu0RZ-B2.4 (zxGUQSmI) — credits surface.
 *
 * Pins the `/api/v2/...` path drift (NOT `/api/credits/...`) and the
 * limit/offset wire shape (NOT from/to/granularity per the codex round-1
 * correction).
 */
#[CoversClass(GislClient::class)]
final class GislClientCreditsTest extends TestCase
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

    private function makeClient(ClientInterface $http): GislClient
    {
        return new GislClient(
            config: new GislClientConfig(baseUrl: 'https://api.example.com', apiKey: 'sk_test'),
            httpClient: $http,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
    }

    public function testGetCreditsBalanceHappyPath(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'monthly_balance' => 350,
                    'purchased_balance' => 100,
                    'overdraft_limit' => 50,
                    'overdraft_debt' => 0,
                    'available_credits' => 500,
                    'monthly_allowance' => 500,
                    'tier' => 'free',
                ],
            ]),
        ], $captured);

        $client = $this->makeClient($http);
        $result = $client->getCreditsBalance();

        self::assertInstanceOf(CreditsBalanceResponse::class, $result);
        self::assertSame(500, $result->getAvailableCredits());

        self::assertCount(1, $captured);
        // Pin the v2 path drift — test fails loudly if anyone shortens to /api/credits/...
        self::assertSame('GET', $captured[0]->getMethod());
        self::assertSame('/api/v2/credits/balance', $captured[0]->getUri()->getPath());
        self::assertSame('', $captured[0]->getUri()->getQuery());
    }

    public function testGetCreditsUsageWithoutOptions(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'transactions' => [],
                    'total' => 0,
                    'limit' => 20,
                    'offset' => 0,
                ],
            ]),
        ], $captured);

        $client = $this->makeClient($http);
        $result = $client->getCreditsUsage();

        self::assertInstanceOf(CreditsUsageResponse::class, $result);

        self::assertCount(1, $captured);
        self::assertSame('/api/v2/credits/usage', $captured[0]->getUri()->getPath());
        self::assertSame('', $captured[0]->getUri()->getQuery(), 'No options means no query string.');
    }

    public function testGetCreditsUsageWithLimitAndOffset(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'transactions' => [],
                    'total' => 0,
                    'limit' => 50,
                    'offset' => 100,
                ],
            ]),
        ], $captured);

        $client = $this->makeClient($http);
        $client->getCreditsUsage(new CreditsUsageOptions(limit: 50, offset: 100));

        self::assertCount(1, $captured);
        self::assertSame('/api/v2/credits/usage', $captured[0]->getUri()->getPath());
        // Query string includes both keys (order from http_build_query is
        // declaration order, which here is limit then offset).
        self::assertSame('limit=50&offset=100', $captured[0]->getUri()->getQuery());
    }

    public function testGetCreditsUsageWithOnlyLimit(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'transactions' => [],
                    'total' => 0,
                    'limit' => 50,
                    'offset' => 0,
                ],
            ]),
        ], $captured);

        $client = $this->makeClient($http);
        $client->getCreditsUsage(new CreditsUsageOptions(limit: 50));

        self::assertCount(1, $captured);
        // Only limit is forwarded — offset key MUST be absent so the server
        // can apply its default of 0 rather than the SDK pinning it.
        self::assertSame('limit=50', $captured[0]->getUri()->getQuery());
    }

    public function testGetCreditsUsageWithOnlyOffset(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'transactions' => [],
                    'total' => 0,
                    'limit' => 20,
                    'offset' => 60,
                ],
            ]),
        ], $captured);

        $client = $this->makeClient($http);
        $client->getCreditsUsage(new CreditsUsageOptions(offset: 60));

        self::assertCount(1, $captured);
        self::assertSame('offset=60', $captured[0]->getUri()->getQuery());
    }
}
