<?php

declare(strict_types=1);

namespace Gisl\Sdk\Tests\Unit\Ergonomic;

use Gisl\Sdk\Ergonomic\OperationBuilder;
use Gisl\Sdk\Ergonomic\PresetResolver;
use Gisl\Sdk\GislClientConfig;
use Gisl\Sdk\GislErgonomicClient;
use Gisl\Sdk\Generated\SdkSpec\Enums\ImageFormat;
use Gisl\Sdk\Generated\SdkSpec\Enums\ImageMetadataPolicy;
use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;
use Gisl\Sdk\Preset\ImageCompressPresetOptions;
use Gisl\Sdk\PresetDefaults;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(GislErgonomicClient::class)]
final class WithPresetDefaultsTest extends TestCase
{
    private ClientInterface $http;

    protected function setUp(): void
    {
        $this->http = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new \LogicException('withPresetDefaults derive must not perform I/O.');
            }
        };
    }

    private function makeClient(?PresetDefaults $presetDefaults = null): GislErgonomicClient
    {
        $factory = new HttpFactory();
        return new GislErgonomicClient(
            config: new GislClientConfig(baseUrl: 'https://api.test', apiKey: 'sk_test'),
            httpClient: $this->http,
            requestFactory: $factory,
            streamFactory: $factory,
            presetDefaults: $presetDefaults,
        );
    }

    private function scopedOf(GislErgonomicClient $client): ?PresetDefaults
    {
        $value = (new \ReflectionObject($client))
            ->getProperty('scopedPresetDefaults')
            ->getValue($client);
        \assert($value === null || $value instanceof PresetDefaults);
        return $value;
    }

    public function testDeriveReturnsDistinctInstance(): void
    {
        $client = $this->makeClient();
        $derived = $client->withPresetDefaults(
            PresetDefaults::create()->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 80)),
        );

        $this->assertInstanceOf(GislErgonomicClient::class, $derived);
        $this->assertNotSame($client, $derived);
    }

    public function testParentScopedUnaffectedByDerive(): void
    {
        $client = $this->makeClient();
        $client->withPresetDefaults(
            PresetDefaults::create()->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 80)),
        );

        // Concurrency invariant: deriving never mutates the parent.
        $this->assertNull($this->scopedOf($client));
    }

    public function testDerivePreservesTransportAndConfigByClone(): void
    {
        $client = $this->makeClient();
        $derived = $client->withPresetDefaults(PresetDefaults::create()->imageCompress(OptimizeFor::Size));

        // httpClient is private on the parent GislClient — reflect on the
        // declaring class to read it on both instances.
        $httpProp = (new \ReflectionClass(\Gisl\Sdk\GislClient::class))->getProperty('httpClient');

        // Same injected transport instance — no discovery / re-resolution.
        $this->assertSame(
            $httpProp->getValue($client),
            $httpProp->getValue($derived),
        );
        // Same config object by reference (baseUrl / apiKey / headers intact).
        $this->assertSame($client->config, $derived->config);
        $this->assertSame('sk_test', $derived->config->apiKey);
    }

    public function testStackedDeriveMergesChildOverParent(): void
    {
        $a = PresetDefaults::create()->imageCompress(
            OptimizeFor::Size,
            new ImageCompressPresetOptions(quality: 70, outputFormat: ImageFormat::Webp),
        );
        $b = PresetDefaults::create()->imageCompress(
            OptimizeFor::Size,
            new ImageCompressPresetOptions(quality: 92),
        );

        $derived = $this->makeClient()->withPresetDefaults($a)->withPresetDefaults($b);
        $scoped = $this->scopedOf($derived);
        $this->assertInstanceOf(PresetDefaults::class, $scoped);

        $cell = $scoped->cellFor('image_compress', OptimizeFor::Size);
        $this->assertInstanceOf(ImageCompressPresetOptions::class, $cell);
        // b wins the overlapping field; a fills the gap it didn't set.
        $this->assertSame(92, $cell->quality);
        $this->assertSame(ImageFormat::Webp, $cell->outputFormat);
    }

    public function testCompressForwardsScopedDefaultsToBuilder(): void
    {
        $scoped = PresetDefaults::create()->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 88));
        $derived = $this->makeClient()->withPresetDefaults($scoped);

        $builder = $derived->compress('/tmp/x.jpg', ['optimize' => 'Size']);
        $this->assertInstanceOf(OperationBuilder::class, $builder);

        $captured = (new \ReflectionObject($builder))
            ->getProperty('scopedPresetDefaults')
            ->getValue($builder);
        $this->assertInstanceOf(PresetDefaults::class, $captured);
        $cell = $captured->cellFor('image_compress', OptimizeFor::Size);
        $this->assertInstanceOf(ImageCompressPresetOptions::class, $cell);
        $this->assertSame(88, $cell->quality);
    }

    public function testRootClientForwardsNullScopedDefaults(): void
    {
        $builder = $this->makeClient()->compress('/tmp/x.jpg');
        $captured = (new \ReflectionObject($builder))
            ->getProperty('scopedPresetDefaults')
            ->getValue($builder);
        $this->assertNull($captured);
    }

    /**
     * End-to-end threading: a derived client's scoped layer must actually
     * reach the resolved WIRE payload (not just sit on the builder). Closes the
     * silent-pass risk where `compress` could forward the wrong preset arg and
     * every resolver-direct test would still pass.
     */
    public function testDerivedScopedThreadsThroughToWire(): void
    {
        $client = $this->makeClient(
            PresetDefaults::create()->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(outputFormat: ImageFormat::Auto)),
        );
        $derived = $client->withPresetDefaults(
            PresetDefaults::create()->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 92)),
        );

        $builder = $derived->compress('/tmp/x.jpg', ['optimize' => 'Size']);
        $ref = new \ReflectionObject($builder);
        $clientDefaults = $ref->getProperty('presetDefaults')->getValue($builder);
        $scopedDefaults = $ref->getProperty('scopedPresetDefaults')->getValue($builder);
        \assert($clientDefaults === null || $clientDefaults instanceof PresetDefaults);
        \assert($scopedDefaults === null || $scopedDefaults instanceof PresetDefaults);

        $out = PresetResolver::resolveCompress('image', $clientDefaults, $scopedDefaults, null, OptimizeFor::Size, []);
        $ro = $out['resolvedOptions'];

        $this->assertSame(92, $out['wireOptions']['quality']);       // scoped
        $this->assertArrayHasKey('output_format', $out['wireOptions']); // client
        $this->assertSame(['quality'], $ro->sources->scopedDefault);
        $this->assertSame(['output_format'], $ro->sources->clientDefault);
    }

    /**
     * Three-layer overlay across a chained derive: client + d1 + d2 each
     * contribute a distinct field, all reaching the wire with correct buckets.
     */
    public function testChainedDeriveThreeLayerOverlay(): void
    {
        $client = $this->makeClient(
            PresetDefaults::create()->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(outputFormat: ImageFormat::Auto)),
        );
        $derived = $client
            ->withPresetDefaults(PresetDefaults::create()->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(quality: 80)))
            ->withPresetDefaults(PresetDefaults::create()->imageCompress(OptimizeFor::Size, new ImageCompressPresetOptions(metadata: ImageMetadataPolicy::All)));

        $builder = $derived->compress('/tmp/x.jpg', ['optimize' => 'Size']);
        $ref = new \ReflectionObject($builder);
        $clientDefaults = $ref->getProperty('presetDefaults')->getValue($builder);
        $scopedDefaults = $ref->getProperty('scopedPresetDefaults')->getValue($builder);
        \assert($clientDefaults === null || $clientDefaults instanceof PresetDefaults);
        \assert($scopedDefaults === null || $scopedDefaults instanceof PresetDefaults);

        $out = PresetResolver::resolveCompress('image', $clientDefaults, $scopedDefaults, null, OptimizeFor::Size, []);

        $this->assertSame(80, $out['wireOptions']['quality']);   // d1 (scoped)
        $this->assertArrayHasKey('metadata', $out['wireOptions']); // d2 (scoped)
        $this->assertArrayHasKey('output_format', $out['wireOptions']); // client
        $this->assertSame(['metadata', 'quality'], $out['resolvedOptions']->sources->scopedDefault);
        $this->assertSame(['output_format'], $out['resolvedOptions']->sources->clientDefault);
    }

    public function testCookieStateIsDeriveTimeSnapshot(): void
    {
        $client = $this->makeClient();
        $derived = $client->withPresetDefaults(PresetDefaults::create()->imageCompress(OptimizeFor::Size));

        // Mutate the parent's session cookie AFTER deriving — the snapshot
        // taken by clone must not propagate to the child (documented divergence
        // from TS's shared-target semantics).
        $cookieProp = (new \ReflectionClass(\Gisl\Sdk\GislClient::class))->getProperty('sessionCookie');
        $cookieProp->setValue($client, 'gisl_session=parent-only');

        $this->assertSame('gisl_session=parent-only', $cookieProp->getValue($client));
        $this->assertNull($cookieProp->getValue($derived));
    }

    public function testEmptyDeriveAttachesNonNullButContributesNothing(): void
    {
        $derived = $this->makeClient()->withPresetDefaults(PresetDefaults::create());
        $scoped = $this->scopedOf($derived);
        $this->assertInstanceOf(PresetDefaults::class, $scoped); // non-null (registered)

        $out = PresetResolver::resolveCompress('image', null, $scoped, null, OptimizeFor::Size, ['quality' => 50]);
        // Empty scoped layer contributes no fields.
        $this->assertSame([], $out['resolvedOptions']->sources->scopedDefault);
        $this->assertSame(['quality'], $out['resolvedOptions']->sources->explicit);
    }
}
