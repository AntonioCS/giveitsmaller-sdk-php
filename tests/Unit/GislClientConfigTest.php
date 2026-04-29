<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit;

use Gisl\Sdk\GislClientConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(GislClientConfig::class)]
final class GislClientConfigTest extends TestCase
{
    public function testDefaultsApplyWhenOptionalsOmitted(): void
    {
        $config = new GislClientConfig(baseUrl: 'https://api.example.com');

        self::assertSame('https://api.example.com', $config->baseUrl);
        self::assertNull($config->apiKey);
        self::assertSame([], $config->headers);
        self::assertFalse($config->useSessionCookie);
        self::assertSame(30_000, $config->timeoutMs);
        self::assertSame(10_000_000, $config->multipartThresholdBytes);
        self::assertSame(4, $config->multipartConcurrency);
        self::assertSame(3, $config->multipartMaxAttempts);
        self::assertSame(500, $config->multipartRetryBaseMs);
    }

    public function testTrailingSlashStrippedFromBaseUrl(): void
    {
        $config = new GislClientConfig(baseUrl: 'https://api.example.com/');
        self::assertSame('https://api.example.com', $config->baseUrl);

        $config = new GislClientConfig(baseUrl: 'https://api.example.com///');
        self::assertSame('https://api.example.com', $config->baseUrl);
    }

    /**
     * @return array<string, array{0: int|null, 1: int}>
     */
    public static function concurrencyProvider(): array
    {
        return [
            'undefined snaps to default'     => [null, 4],
            'zero snaps to default'          => [0, 4],
            'negative snaps to default'      => [-7, 4],
            'one passes through'             => [1, 1],
            'normal positive int passes'     => [3, 3],
            'large value passes'             => [16, 16],
        ];
    }

    #[DataProvider('concurrencyProvider')]
    public function testMultipartConcurrencySanitiser(?int $input, int $expected): void
    {
        $config = new GislClientConfig(
            baseUrl: 'https://api.example.com',
            multipartConcurrency: $input,
        );
        self::assertSame($expected, $config->multipartConcurrency);
    }

    /**
     * @return array<string, array{0: int|null, 1: int}>
     */
    public static function attemptsProvider(): array
    {
        return [
            'undefined defaults to 3'    => [null, 3],
            'zero floors to 1'           => [0, 1],
            'negative floors to 1'       => [-2, 1],
            'one passes through'         => [1, 1],
            'three passes through'       => [3, 3],
        ];
    }

    #[DataProvider('attemptsProvider')]
    public function testMultipartMaxAttemptsSanitiser(?int $input, int $expected): void
    {
        $config = new GislClientConfig(
            baseUrl: 'https://api.example.com',
            multipartMaxAttempts: $input,
        );
        self::assertSame($expected, $config->multipartMaxAttempts);
    }

    /**
     * @return array<string, array{0: int|null, 1: int}>
     */
    public static function retryBaseMsProvider(): array
    {
        return [
            'undefined defaults to 500'    => [null, 500],
            'zero permitted (no backoff)'  => [0, 0],
            'negative floors to 0'         => [-50, 0],
            'positive passes through'      => [1000, 1000],
        ];
    }

    #[DataProvider('retryBaseMsProvider')]
    public function testMultipartRetryBaseMsSanitiser(?int $input, int $expected): void
    {
        $config = new GislClientConfig(
            baseUrl: 'https://api.example.com',
            multipartRetryBaseMs: $input,
        );
        self::assertSame($expected, $config->multipartRetryBaseMs);
    }

    public function testMultipartThresholdFlooredAtFirstChunkSize(): void
    {
        // Sub-8MB threshold gets raised to 8 MiB to satisfy the multipart
        // first-chunk contract.
        $config = new GislClientConfig(
            baseUrl: 'https://api.example.com',
            multipartThresholdBytes: 1_000_000,
        );
        self::assertSame(8_388_608, $config->multipartThresholdBytes);
    }

    public function testMultipartThresholdAcceptsValueAboveFloor(): void
    {
        $config = new GislClientConfig(
            baseUrl: 'https://api.example.com',
            multipartThresholdBytes: 50_000_000,
        );
        self::assertSame(50_000_000, $config->multipartThresholdBytes);
    }

    public function testTimeoutBelowOneFallsBack(): void
    {
        $config = new GislClientConfig(
            baseUrl: 'https://api.example.com',
            timeout: 0,
        );
        self::assertSame(30_000, $config->timeoutMs);

        $config = new GislClientConfig(
            baseUrl: 'https://api.example.com',
            timeout: -10,
        );
        self::assertSame(30_000, $config->timeoutMs);
    }

    public function testTimeoutAcceptsMilliseconds(): void
    {
        $config = new GislClientConfig(
            baseUrl: 'https://api.example.com',
            timeout: 5_000,
        );
        self::assertSame(5_000, $config->timeoutMs);
    }

    public function testUseSessionCookieRejectedUntilLandingInVOxtu0RZB(): void
    {
        $this->expectException(\Gisl\Sdk\Errors\GislConfigError::class);
        $this->expectExceptionMessageMatches('/useSessionCookie is not yet implemented/');
        new GislClientConfig(
            baseUrl: 'https://api.example.com',
            useSessionCookie: true,
        );
    }

    public function testCustomHeadersStored(): void
    {
        $config = new GislClientConfig(
            baseUrl: 'https://api.example.com',
            headers: ['X-Trace-Id' => 'abc123', 'User-Agent' => 'custom/1.0'],
        );
        self::assertSame(['X-Trace-Id' => 'abc123', 'User-Agent' => 'custom/1.0'], $config->headers);
    }
}
