<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Generated\OpenApi\Model\AccountLimitEntry;
use Gisl\Generated\OpenApi\Model\AccountLimits;
use Gisl\Generated\OpenApi\Model\AccountLimitsLimits;
use Gisl\Generated\OpenApi\Model\CreditsBalanceResponse;
use Gisl\Generated\OpenApi\Model\CreditsUsageResponse;
use Gisl\Generated\OpenApi\Model\UserTier;
use Gisl\Sdk\CreditsUsageOptions;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\GislErgonomicClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Unit coverage for 8yqUXLCS — `getAccountLimits()` low-level getter plus the
 * first-class ergonomic billing/limits accessors (`credits()`,
 * `creditsUsage()`, `limits()`).
 *
 * Mirrors the mock-transport style of {@see GislClientCreditsTest}: a stub
 * PSR-18 client captures the outbound request so each test can pin the exact
 * `/api/v2/...` path + method (path-drift guard) and assert the deserialized
 * return type. The ergonomic accessors are exercised on a directly-constructed
 * {@see GislErgonomicClient} (the `Gisl::create()` factory's credential-chain
 * resolution is not relevant here — the accessor → low-level delegation is).
 */
#[CoversClass(GislErgonomicClient::class)]
final class GislClientAccountLimitsTest extends TestCase
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

    private function makeClient(ClientInterface $http): GislErgonomicClient
    {
        return new GislErgonomicClient(
            config: new GislClientConfig(baseUrl: 'https://api.example.com', apiKey: 'sk_test'),
            httpClient: $http,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
    }

    /**
     * Canonical `data` body for a `GET /api/v2/account/limits` success envelope.
     * `pro` tier with one default + one overridden limit so the nested-typing
     * assertions have both `overridden` states to check.
     *
     * @return array<string, mixed>
     */
    private function accountLimitsData(): array
    {
        return [
            'tier' => 'pro',
            'limits' => [
                'max_upload_size_bytes' => [
                    'effective' => 5368709120,
                    'tier_default' => 5368709120,
                    'overridden' => false,
                ],
                'max_total_input_size_bytes' => [
                    'effective' => 5000000000,
                    'tier_default' => 1073741824,
                    'overridden' => true,
                ],
            ],
        ];
    }

    public function testGetAccountLimitsHappyPath(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => $this->accountLimitsData(),
            ]),
        ], $captured);

        $client = $this->makeClient($http);
        $result = $client->getAccountLimits();

        self::assertInstanceOf(AccountLimits::class, $result);
        self::assertSame(UserTier::PRO, $result->getTier());

        // Nested `data` is fully hydrated (deserialize, not shallow construct):
        // `limits` and each entry come back as typed objects, not raw arrays.
        self::assertInstanceOf(AccountLimitsLimits::class, $result->getLimits());

        $uploadEntry = $result->getLimits()->getMaxUploadSizeBytes();
        self::assertInstanceOf(AccountLimitEntry::class, $uploadEntry);
        self::assertSame(5368709120, $uploadEntry->getEffective());
        self::assertSame(5368709120, $uploadEntry->getTierDefault());
        self::assertFalse($uploadEntry->getOverridden());

        $totalEntry = $result->getLimits()->getMaxTotalInputSizeBytes();
        self::assertInstanceOf(AccountLimitEntry::class, $totalEntry);
        self::assertSame(5000000000, $totalEntry->getEffective());
        self::assertSame(1073741824, $totalEntry->getTierDefault());
        self::assertTrue($totalEntry->getOverridden());

        // Path-drift guard — fails loudly if anyone moves the endpoint off the
        // canonical `/api/v2/account/limits`.
        self::assertCount(1, $captured);
        self::assertSame('GET', $captured[0]->getMethod());
        self::assertSame('/api/v2/account/limits', $captured[0]->getUri()->getPath());
        self::assertSame('', $captured[0]->getUri()->getQuery());
    }

    public function testLimitsAccessorDelegatesToAccountLimitsEndpoint(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, [
                'success' => true,
                'data' => $this->accountLimitsData(),
            ]),
        ], $captured);

        $client = $this->makeClient($http);
        $result = $client->limits();

        // `limits()` is sugar over `getAccountLimits()` — same return type, same
        // endpoint, no query string.
        self::assertInstanceOf(AccountLimits::class, $result);
        self::assertSame(UserTier::PRO, $result->getTier());

        self::assertCount(1, $captured);
        self::assertSame('GET', $captured[0]->getMethod());
        self::assertSame('/api/v2/account/limits', $captured[0]->getUri()->getPath());
        self::assertSame('', $captured[0]->getUri()->getQuery());
    }

    public function testCreditsAccessorDelegatesToCreditsBalanceEndpoint(): void
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
        $result = $client->credits();

        // `credits()` is sugar over `getCreditsBalance()`.
        self::assertInstanceOf(CreditsBalanceResponse::class, $result);
        self::assertSame(500, $result->getAvailableCredits());

        self::assertCount(1, $captured);
        self::assertSame('GET', $captured[0]->getMethod());
        self::assertSame('/api/v2/credits/balance', $captured[0]->getUri()->getPath());
        self::assertSame('', $captured[0]->getUri()->getQuery());
    }

    public function testCreditsUsageAccessorWithoutOptionsHitsUsageEndpoint(): void
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
        $result = $client->creditsUsage();

        self::assertInstanceOf(CreditsUsageResponse::class, $result);

        self::assertCount(1, $captured);
        self::assertSame('GET', $captured[0]->getMethod());
        self::assertSame('/api/v2/credits/usage', $captured[0]->getUri()->getPath());
        self::assertSame('', $captured[0]->getUri()->getQuery(), 'No options means no query string.');
    }

    public function testCreditsUsageAccessorForwardsLimitAndOffsetQuery(): void
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
        $result = $client->creditsUsage(new CreditsUsageOptions(limit: 50, offset: 100));

        self::assertInstanceOf(CreditsUsageResponse::class, $result);

        // The accessor forwards options through to `getCreditsUsage()`, which
        // builds the query string in declaration order (limit then offset).
        self::assertCount(1, $captured);
        self::assertSame('/api/v2/credits/usage', $captured[0]->getUri()->getPath());
        self::assertSame('limit=50&offset=100', $captured[0]->getUri()->getQuery());
    }
}
