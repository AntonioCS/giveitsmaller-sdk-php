<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Generated\OpenApi\Model\LoginUser200ResponseData;
use Gisl\Generated\OpenApi\Model\LoginUserRequest;
use Gisl\Sdk\Errors\GislApiError;
use Gisl\Sdk\Errors\GislAuthError;
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
 * Unit coverage for VOxtu0RZ-B2.4 (zxGUQSmI) — auth surface.
 *
 * Pins:
 *  - login: typed-DTO round-trip, Set-Cookie capture (only when
 *    useSessionCookie=true), forwarding of Cookie: on subsequent requests.
 *  - logout: 200 happy path, 401-as-success swallow, 5xx still throws,
 *    cookie cleared on every outcome.
 *  - GislClientConfig: useSessionCookie=true is no longer rejected.
 */
#[CoversClass(GislClient::class)]
final class GislClientAuthTest extends TestCase
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
     * @param array<string, string|list<string>> $extraHeaders
     */
    private function jsonResponse(int $status, array $body, array $extraHeaders = []): ResponseInterface
    {
        $encoded = \json_encode($body, JSON_THROW_ON_ERROR);
        $headers = ['Content-Type' => 'application/json'];
        foreach ($extraHeaders as $name => $value) {
            $headers[$name] = $value;
        }
        return new Response($status, $headers, $encoded);
    }

    private function makeClient(
        ClientInterface $http,
        bool $useSessionCookie = false,
    ): GislClient {
        return new GislClient(
            config: new GislClientConfig(
                baseUrl: 'https://api.example.com',
                apiKey: null, // cookie auth flows usually omit the bearer
                useSessionCookie: $useSessionCookie,
            ),
            httpClient: $http,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function happyLoginEnvelope(): array
    {
        return [
            'success' => true,
            'data' => [
                'user' => [
                    'id' => '01936fb2-0000-7000-8000-000000000abc',
                    'email' => 'alice@example.com',
                    'tier' => 'free',
                ],
            ],
        ];
    }

    public function testLoginHappyPathReturnsTypedResponse(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, $this->happyLoginEnvelope()),
        ], $captured);

        $client = $this->makeClient($http);
        $result = $client->login(new LoginUserRequest(['email' => 'alice@example.com', 'password' => 'pw']));

        self::assertInstanceOf(LoginUser200ResponseData::class, $result);
        self::assertNotNull($result->getUser());
        self::assertSame('alice@example.com', $result->getUser()->getEmail());

        self::assertCount(1, $captured);
        $request = $captured[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/api/auth/login', $request->getUri()->getPath());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));

        $body = \json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('alice@example.com', $body['email']);
        self::assertSame('pw', $body['password']);
    }

    public function testLoginPersistsCookieWhenUseSessionCookieTrue(): void
    {
        $captured = [];
        $http = $this->stubClient([
            // Login response with Set-Cookie.
            $this->jsonResponse(
                200,
                $this->happyLoginEnvelope(),
                ['Set-Cookie' => 'gisl_session=abc123; Path=/; HttpOnly; SameSite=Lax'],
            ),
            // Subsequent stub for a follow-up request — driven via getCreditsBalance.
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'monthly_balance' => 100,
                    'purchased_balance' => 0,
                    'overdraft_limit' => 0,
                    'overdraft_debt' => 0,
                    'available_credits' => 100,
                    'monthly_allowance' => 100,
                    'tier' => 'free',
                ],
            ]),
        ], $captured);

        $client = $this->makeClient($http, useSessionCookie: true);
        $client->login(new LoginUserRequest(['email' => 'alice@example.com', 'password' => 'pw']));
        $client->getCreditsBalance();

        self::assertCount(2, $captured);
        // First request: login itself does not yet have the cookie.
        self::assertFalse(
            $captured[0]->hasHeader('Cookie'),
            'login() request must not carry a Cookie header (server hasn\'t set it yet).',
        );
        // Second request: cookie captured from Set-Cookie above is forwarded.
        self::assertSame(
            'gisl_session=abc123',
            $captured[1]->getHeaderLine('Cookie'),
            'Subsequent request must forward the captured gisl_session value.',
        );
    }

    public function testLoginDoesNotPersistCookieWhenUseSessionCookieFalse(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(
                200,
                $this->happyLoginEnvelope(),
                ['Set-Cookie' => 'gisl_session=abc123; Path=/; HttpOnly'],
            ),
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'monthly_balance' => 100,
                    'purchased_balance' => 0,
                    'overdraft_limit' => 0,
                    'overdraft_debt' => 0,
                    'available_credits' => 100,
                    'monthly_allowance' => 100,
                    'tier' => 'free',
                ],
            ]),
        ], $captured);

        // useSessionCookie=false (default) — even when the server returns
        // Set-Cookie, the SDK ignores it. Bearer-token flows must never
        // accidentally pick up cookies.
        $client = $this->makeClient($http, useSessionCookie: false);
        $client->login(new LoginUserRequest(['email' => 'alice@example.com', 'password' => 'pw']));
        $client->getCreditsBalance();

        self::assertCount(2, $captured);
        self::assertFalse(
            $captured[1]->hasHeader('Cookie'),
            'Subsequent request must not carry Cookie when useSessionCookie=false.',
        );
    }

    public function testLogoutHappyPath200(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(200, ['success' => true, 'data' => ['ok' => true]]),
        ], $captured);

        $client = $this->makeClient($http);
        $client->logout(); // returns void; assertion is "no throw"

        self::assertCount(1, $captured);
        self::assertSame('POST', $captured[0]->getMethod());
        self::assertSame('/api/auth/logout', $captured[0]->getUri()->getPath());
    }

    public function testLogoutHappyPath204NoContent(): void
    {
        // Server may return 204 (no body); the void short-circuit must accept
        // it without tripping unwrapEnvelope's empty-body guard.
        $captured = [];
        $http = $this->stubClient([
            new Response(204, [], ''),
        ], $captured);

        $client = $this->makeClient($http);
        $client->logout();

        self::assertCount(1, $captured);
    }

    public function testLogout401IsSwallowedAsSuccess(): void
    {
        // 401 = already-logged-out per the contract. logout() must NOT
        // bubble this up; callers just want the local session cleared.
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(401, [
                'success' => false,
                'error' => 'invalid_session',
                'message' => 'No active session.',
            ]),
        ], $captured);

        $client = $this->makeClient($http);
        $client->logout(); // no exception — collapses 401 onto void
        self::assertCount(1, $captured);
    }

    public function testLogout5xxStillThrows(): void
    {
        $captured = [];
        $http = $this->stubClient([
            $this->jsonResponse(503, [
                'success' => false,
                'error' => 'service_unavailable',
                'message' => 'Backend overloaded.',
            ]),
        ], $captured);

        $client = $this->makeClient($http);

        try {
            $client->logout();
            self::fail('Expected GislApiError on 5xx');
        } catch (GislApiError $e) {
            self::assertNotInstanceOf(GislAuthError::class, $e);
            self::assertSame(503, $e->statusCode);
            self::assertSame('service_unavailable', $e->errorCode);
        }
    }

    public function testLogoutClearsCookieEvenOn401(): void
    {
        // After login captures the cookie, logout MUST clear it on every
        // outcome — including the 401-swallow path. A subsequent request
        // must NOT carry the stale Cookie header.
        $captured = [];
        $http = $this->stubClient([
            // login
            $this->jsonResponse(
                200,
                $this->happyLoginEnvelope(),
                ['Set-Cookie' => 'gisl_session=stale_token; Path=/'],
            ),
            // logout returns 401 (already-logged-out, swallowed)
            $this->jsonResponse(401, [
                'success' => false,
                'error' => 'invalid_session',
                'message' => 'No active session.',
            ]),
            // follow-up: must have no Cookie
            $this->jsonResponse(200, [
                'success' => true,
                'data' => [
                    'monthly_balance' => 0,
                    'purchased_balance' => 0,
                    'overdraft_limit' => 0,
                    'overdraft_debt' => 0,
                    'available_credits' => 0,
                    'monthly_allowance' => 0,
                    'tier' => 'free',
                ],
            ]),
        ], $captured);

        $client = $this->makeClient($http, useSessionCookie: true);
        $client->login(new LoginUserRequest(['email' => 'alice@example.com', 'password' => 'pw']));
        $client->logout();
        $client->getCreditsBalance();

        self::assertCount(3, $captured);
        // The 3rd request (post-logout) must not carry the stale cookie.
        self::assertFalse(
            $captured[2]->hasHeader('Cookie'),
            'Cookie must be cleared after logout, even on the 401-swallow path.',
        );
    }

    public function testUseSessionCookieTrueNoLongerThrowsAtConstructor(): void
    {
        // Regression guard for the rejection removal. Before zxGUQSmI the
        // constructor threw GislConfigError; now it accepts the flag.
        $config = new GislClientConfig(
            baseUrl: 'https://api.example.com',
            useSessionCookie: true,
        );
        self::assertTrue($config->useSessionCookie);
    }
}
