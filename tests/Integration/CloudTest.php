<?php

declare(strict_types=1);

namespace Weaviate\Client\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Weaviate\Client\Connect\AdditionalConfig;
use Weaviate\Client\Connect\Auth;
use Weaviate\Client\Connect\GrpcTransportChoice;
use Weaviate\Client\Exceptions\AuthenticationException;
use Weaviate\Client\Proto\V1\MetadataRequest;
use Weaviate\Client\Proto\V1\PropertiesRequest;
use Weaviate\Client\Proto\V1\SearchReply;
use Weaviate\Client\Proto\V1\SearchRequest;
use Weaviate\Client\Proto\V1\TenantsGetReply;
use Weaviate\Client\Proto\V1\TenantsGetRequest;
use Weaviate\Client\Transport\Grpc\ExtGrpcTransport;
use Weaviate\Client\Weaviate;
use Weaviate\Client\WeaviateClient;

/**
 * ADR 0002 spike, criterion 1b: gRPC over TLS against Weaviate Cloud.
 *
 * **Read-only**: it searches the first existing collection and never writes, so it's safe to point at a real
 * cluster. Skipped unless both variables are set; credentials are never stored in the repo:
 *
 *   WEAVIATE_CLOUD_URL=... WEAVIATE_CLOUD_API_KEY=... bin/php vendor/bin/phpunit --group cloud
 */
#[Group('cloud')]
final class CloudTest extends TestCase
{
    /**
     * @return iterable<string, array{GrpcTransportChoice}>
     */
    public static function transports(): iterable
    {
        yield 'curl' => [GrpcTransportChoice::Curl];
        yield 'ext-grpc' => [GrpcTransportChoice::ExtGrpc];
    }

    #[DataProvider('transports')]
    public function testReadOnlyGrpcOverTls(GrpcTransportChoice $transport): void
    {
        $client = $this->connect($transport);

        try {
            self::assertTrue($client->isReady());
            self::assertTrue($client->isLive(), 'isLive includes the gRPC health check over TLS');

            $schema = $client->restTransport()->requestExpecting('GET', '/schema', 'Get schema')->body;
            self::assertIsArray($schema);
            $classes = \is_array($schema['classes'] ?? null) ? $schema['classes'] : [];
            if ($classes === []) {
                self::markTestSkipped('The cluster has no collections to search (the test is read-only).');
            }
            $class = $classes[0];
            self::assertIsArray($class);
            $name = $class['class'];
            self::assertIsString($name);

            $tenant = '';
            $multiTenant = \is_array($class['multiTenancyConfig'] ?? null) && ($class['multiTenancyConfig']['enabled'] ?? false) === true;
            if ($multiTenant) {
                $tenants = $client->grpcTransport()->unary(
                    '/weaviate.v1.Weaviate/TenantsGet',
                    new TenantsGetRequest(['collection' => $name]),
                    TenantsGetReply::class,
                    30.0,
                    $client->grpcMetadata(),
                );
                if (\count($tenants->getTenants()) === 0) {
                    self::markTestSkipped(\sprintf('%s is multi-tenant but has no tenants', $name));
                }
                $tenant = $tenants->getTenants()[0]->getName();
            }

            $reply = $client->grpcTransport()->unary('/weaviate.v1.Weaviate/Search', new SearchRequest([
                'collection' => $name,
                'tenant' => $tenant,
                'limit' => 3,
                'uses_127_api' => true,
                'properties' => new PropertiesRequest(['non_ref_properties' => []]),
                'metadata' => new MetadataRequest(['uuid' => true, 'creation_time_unix' => true]),
            ]), SearchReply::class, 30.0, $client->grpcMetadata());

            self::assertLessThanOrEqual(3, \count($reply->getResults()));
            foreach ($reply->getResults() as $result) {
                self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $result->getMetadata()?->getId());
            }
        } finally {
            $client->close();
        }
    }

    public function testWrongApiKeyIsRejected(): void
    {
        [$url] = self::credentials();

        // A 401 on /v1/meta surfaces as AuthenticationException, not a generic startup failure.
        $this->expectException(AuthenticationException::class);
        Weaviate::connectToWeaviateCloud(clusterUrl: $url, auth: Auth::apiKey('not-a-valid-key'));
    }

    private function connect(GrpcTransportChoice $transport): WeaviateClient
    {
        if ($transport === GrpcTransportChoice::ExtGrpc && !ExtGrpcTransport::isSupported()) {
            self::markTestSkipped('ext-grpc is not loaded');
        }
        [$url, $apiKey] = self::credentials();

        $client = Weaviate::connectToWeaviateCloud(
            clusterUrl: $url,
            auth: Auth::apiKey($apiKey),
            additionalConfig: new AdditionalConfig(timeout: [30, 60], grpcTransport: $transport),
        );
        self::assertSame($transport === GrpcTransportChoice::Curl ? 'curl' : 'ext-grpc', $client->grpcTransport()->name());

        return $client;
    }

    /**
     * @return array{string, string}
     */
    private static function credentials(): array
    {
        $url = getenv('WEAVIATE_CLOUD_URL');
        $apiKey = getenv('WEAVIATE_CLOUD_API_KEY');
        if (!\is_string($url) || $url === '' || !\is_string($apiKey) || $apiKey === '') {
            self::markTestSkipped('WEAVIATE_CLOUD_URL and WEAVIATE_CLOUD_API_KEY are not set');
        }

        return [$url, $apiKey];
    }
}
