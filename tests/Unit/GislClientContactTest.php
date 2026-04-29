<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Generated\OpenApi\Model\ContactRequest;
use Gisl\Sdk\Errors\GislValidationError;
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
 * Unit coverage for VOxtu0RZ-B2.4 (zxGUQSmI) — contact form submission.
 *
 * Pins the dual happy-path (204 No Content default; 200 envelope when the
 * gateway bridges) and the validation-envelope dispatch onto the typed
 * {@see GislValidationError} from B2.1.
 */
#[CoversClass(GislClient::class)]
final class GislClientContactTest extends TestCase
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

    private function makeClient(ClientInterface $http): GislClient
    {
        return new GislClient(
            config: new GislClientConfig(baseUrl: 'https://api.example.com'),
            httpClient: $http,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
    }

    private function buildPayload(): ContactRequest
    {
        return new ContactRequest([
            'name' => 'Alice Tester',
            'email' => 'alice@example.com',
            'subject' => 'general_enquiry',
            'message' => 'Hello, I need help.',
            'website' => '', // honeypot — must be empty for legitimate submissions
        ]);
    }

    public function testSubmitContact204Path(): void
    {
        // Default contract path: 204 No Content with empty body.
        $captured = [];
        $http = $this->stubClient([
            new Response(204, [], ''),
        ], $captured);

        $client = $this->makeClient($http);
        $client->submitContact($this->buildPayload()); // void; assertion is "no throw"

        self::assertCount(1, $captured);
        $request = $captured[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/api/contact', $request->getUri()->getPath());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));

        $body = \json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('alice@example.com', $body['email']);
        self::assertSame('general_enquiry', $body['subject']);
    }

    public function testSubmitContactWith200EnvelopeAlsoSucceeds(): void
    {
        // Some gateway shapes bridge 204 to 200 with a trivial envelope —
        // the void short-circuit must accept that path too without throwing.
        $captured = [];
        $http = $this->stubClient([
            new Response(
                200,
                ['Content-Type' => 'application/json'],
                \json_encode(['success' => true, 'data' => ['ok' => true]], JSON_THROW_ON_ERROR),
            ),
        ], $captured);

        $client = $this->makeClient($http);
        $client->submitContact($this->buildPayload());

        self::assertCount(1, $captured);
    }

    public function testSubmitContactErrorEnvelopeThrowsValidationError(): void
    {
        // 422 with a B2.1 validation envelope — must dispatch onto the
        // typed GislValidationError, NOT the generic GislApiError.
        $captured = [];
        $http = $this->stubClient([
            new Response(
                422,
                ['Content-Type' => 'application/json'],
                \json_encode([
                    'success' => false,
                    'error' => 'validation_failed',
                    'message' => 'email is required',
                    'details' => [
                        ['field' => 'email', 'message' => 'email is required'],
                    ],
                ], JSON_THROW_ON_ERROR),
            ),
        ], $captured);

        $client = $this->makeClient($http);

        try {
            $client->submitContact($this->buildPayload());
            self::fail('Expected GislValidationError');
        } catch (GislValidationError $e) {
            self::assertSame(422, $e->statusCode);
            self::assertSame('validation_failed', $e->errorCode);
            self::assertNotNull($e->typedPayload);
            $details = $e->typedPayload->getDetails();
            self::assertIsArray($details);
            self::assertCount(1, $details);
            self::assertSame('email is required', $details[0]->getMessage());
        }
    }
}
