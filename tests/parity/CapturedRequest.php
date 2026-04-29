<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Parity;

/**
 * Normalised view of one request captured by {@see StubPsr18Client}.
 *
 * The shape is language-neutral — `Comparator` operates on this canonical
 * structure so it doesn't need to know whether the SDK uses Guzzle, Symfony
 * HttpClient, or another PSR-18 implementation.
 *
 * For multipart, `multipartParts` is set and `body` carries the empty marker
 * `['type' => 'multipart']`. For json/text/raw, `body` carries the parsed
 * payload directly.
 */
final class CapturedRequest
{
    /**
     * @param array<string, list<string>> $query     Query parameters; one
     *                                               key may have multiple
     *                                               values (PHP allows
     *                                               `?a=1&a=2`).
     * @param array<string, string>       $headers   Lowercase keys.
     * @param array<string, mixed>        $body      Captured body envelope:
     *                                               `{ type: empty | json | text | raw | multipart, ... }`.
     * @param list<array<string, mixed>>  $multipartParts Set when body.type=multipart.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly string $path,
        public readonly array $query,
        public readonly array $headers,
        public readonly array $body,
        public readonly array $multipartParts = [],
    ) {
    }
}
