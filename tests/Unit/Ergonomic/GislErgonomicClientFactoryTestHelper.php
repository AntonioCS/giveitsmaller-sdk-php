<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Ergonomic;

use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\GislErgonomicClient;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Tiny shared builder for tests that need a `GislErgonomicClient` whose
 * HTTP transport throws on any outbound request. Useful for tests that
 * only exercise the builder factory + assertion paths without driving
 * the wire.
 */
final class GislErgonomicClientFactoryTestHelper
{
    public static function client(): GislErgonomicClient
    {
        $factory = new HttpFactory();
        $http = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new \LogicException(
                    'GislErgonomicClientFactoryTestHelper: factory tests must not perform I/O '
                    . '(request was: ' . $request->getMethod() . ' ' . $request->getUri() . ').',
                );
            }
        };
        return new GislErgonomicClient(
            config: new GislClientConfig(
                baseUrl: 'https://api.test',
                apiKey: 'sk_test',
                multipartConcurrency: 1,
            ),
            httpClient: $http,
            requestFactory: $factory,
            streamFactory: $factory,
        );
    }
}
