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
use Weaviate\Client\Exceptions\GrpcException;
use Weaviate\Client\Proto\V1\MetadataRequest;
use Weaviate\Client\Proto\V1\NearVector;
use Weaviate\Client\Proto\V1\PropertiesRequest;
use Weaviate\Client\Proto\V1\SearchReply;
use Weaviate\Client\Proto\V1\SearchRequest;
use Weaviate\Client\Proto\V1\TenantsGetReply;
use Weaviate\Client\Proto\V1\TenantsGetRequest;
use Weaviate\Client\Proto\V1\Value;
use Weaviate\Client\Proto\V1\Vectors;
use Weaviate\Client\Proto\V1\Vectors\VectorType;
use Weaviate\Client\Transport\Grpc\ExtGrpcTransport;
use Weaviate\Client\Transport\Grpc\GrpcStatus;
use Weaviate\Client\Transport\Rest\RestTransport;
use Weaviate\Client\Weaviate;
use Weaviate\Client\WeaviateClient;

/**
 * gRPC over TLS against a real Weaviate Cloud cluster (ADR 0002 spike, criterion 1b).
 *
 * Uses a **persistent fixture collection**, `PhpClientCloudTest`: it's multi-tenant with two tenants and
 * objects at fixed UUIDs. It's created if missing and upserted idempotently, and left in place, so the test
 * works on sandbox clusters limited to one collection. Point it at a test cluster only.
 *
 *   WEAVIATE_CLOUD_URL=... WEAVIATE_CLOUD_API_KEY=... bin/php vendor/bin/phpunit --group cloud
 *
 * Skipped unless both variables are set; credentials are never stored in the repo.
 */
#[Group('cloud')]
final class CloudTest extends TestCase
{
    public const COLLECTION = 'PhpClientCloudTest';
    private const FIXTURE_VERSION = 'weaviate-php-client cloud test fixture v1';
    private const SEARCH = '/weaviate.v1.Weaviate/Search';

    /** tenant => [uuid => [title, year, vector]] */
    private const OBJECTS = [
        'tenant_a' => [
            '00000000-0000-4000-8000-00000000a001' => ['alpha', 2021, [1.0, 0.0, 0.0]],
            '00000000-0000-4000-8000-00000000a002' => ['beta', 2022, [0.0, 1.0, 0.0]],
            '00000000-0000-4000-8000-00000000a003' => ['gamma', 2023, [0.0, 0.0, 1.0]],
        ],
        'tenant_b' => [
            '00000000-0000-4000-8000-00000000b001' => ['delta', 2024, [1.0, 1.0, 0.0]],
        ],
    ];

    private static bool $fixtureReady = false;

    /**
     * @return iterable<string, array{GrpcTransportChoice}>
     */
    public static function transports(): iterable
    {
        yield 'curl' => [GrpcTransportChoice::Curl];
        yield 'ext-grpc' => [GrpcTransportChoice::ExtGrpc];
    }

    #[DataProvider('transports')]
    public function testHealthOverTls(GrpcTransportChoice $transport): void
    {
        $client = $this->connect($transport);

        self::assertTrue($client->isReady());
        self::assertTrue($client->isLive(), 'isLive includes the gRPC health check over TLS');
        self::assertTrue($client->serverVersion()->isAtLeast(1, 29));
        $client->close();
    }

    #[DataProvider('transports')]
    public function testTenantsGet(GrpcTransportChoice $transport): void
    {
        $client = $this->connectWithFixture($transport);

        $reply = $client->grpcTransport()->unary(
            '/weaviate.v1.Weaviate/TenantsGet',
            new TenantsGetRequest(['collection' => self::COLLECTION]),
            TenantsGetReply::class,
            30.0,
            $client->grpcMetadata(),
        );
        $names = [];
        foreach ($reply->getTenants() as $tenant) {
            $names[] = $tenant->getName();
        }
        sort($names);

        self::assertSame(['tenant_a', 'tenant_b'], $names);
        $client->close();
    }

    #[DataProvider('transports')]
    public function testFetchObjectsIsScopedToTheTenant(GrpcTransportChoice $transport): void
    {
        $client = $this->connectWithFixture($transport);

        self::assertSame(['alpha', 'beta', 'gamma'], $this->titles($this->search($client, 'tenant_a', limit: 10)));
        self::assertSame(['delta'], $this->titles($this->search($client, 'tenant_b', limit: 10)));
        $client->close();
    }

    #[DataProvider('transports')]
    public function testNearVector(GrpcTransportChoice $transport): void
    {
        $client = $this->connectWithFixture($transport);

        $reply = $this->search($client, 'tenant_a', limit: 1, nearVector: [0.0, 1.0, 0.0]);

        self::assertSame(['beta'], $this->titles($reply));
        self::assertSame('00000000-0000-4000-8000-00000000a002', $reply->getResults()[0]->getMetadata()?->getId());
        self::assertEqualsWithDelta(0.0, $reply->getResults()[0]->getMetadata()->getDistance(), 1e-6);
        $client->close();
    }

    #[DataProvider('transports')]
    public function testSearchWithoutTenantOnMultiTenantCollectionFails(GrpcTransportChoice $transport): void
    {
        $client = $this->connectWithFixture($transport);

        try {
            $this->search($client, '', limit: 1);
            self::fail('expected a GrpcException');
        } catch (GrpcException $e) {
            self::assertNotSame(GrpcStatus::Ok, $e->grpcStatus);
            self::assertStringContainsStringIgnoringCase('tenant', $e->grpcMessage);
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

    private function connectWithFixture(GrpcTransportChoice $transport): WeaviateClient
    {
        $client = $this->connect($transport);
        if (!self::$fixtureReady) {
            self::ensureFixture($client);
            self::$fixtureReady = true;
        }

        return $client;
    }

    /**
     * Creates the fixture collection and tenants if they're missing, and upserts the objects at fixed UUIDs
     * (batch writes overwrite by id), so reruns are idempotent.
     */
    private static function ensureFixture(WeaviateClient $client): void
    {
        $rest = $client->restTransport();
        $existing = $rest->request('GET', RestTransport::path('schema', self::COLLECTION));

        if ($existing->statusCode === 200) {
            $description = \is_array($existing->body) ? ($existing->body['description'] ?? null) : null;
            if ($description !== self::FIXTURE_VERSION) {
                $rest->requestExpecting('DELETE', RestTransport::path('schema', self::COLLECTION), 'Drop outdated fixture');
                $existing = $rest->request('GET', RestTransport::path('schema', self::COLLECTION));
            }
        }
        if ($existing->statusCode !== 200) {
            $created = $rest->request('POST', '/schema', [
                'class' => self::COLLECTION,
                'description' => self::FIXTURE_VERSION,
                'vectorizer' => 'none',
                'multiTenancyConfig' => ['enabled' => true],
                'properties' => [
                    ['name' => 'title', 'dataType' => ['text']],
                    ['name' => 'year', 'dataType' => ['int']],
                ],
            ]);
            if ($created->statusCode === 429) {
                self::markTestSkipped('The cluster is at its collection limit; delete other collections to run the cloud tests.');
            }
            if (!$created->isSuccessful()) {
                self::fail(\sprintf('Creating the fixture failed with %d: %s', $created->statusCode, json_encode($created->body)));
            }
        }

        $tenants = $rest->requestExpecting('GET', RestTransport::path('schema', self::COLLECTION, 'tenants'), 'Get tenants')->body;
        $have = [];
        foreach (\is_array($tenants) ? $tenants : [] as $tenant) {
            if (\is_array($tenant) && \is_string($tenant['name'] ?? null)) {
                $have[] = $tenant['name'];
            }
        }
        $missing = array_values(array_diff(array_keys(self::OBJECTS), $have));
        if ($missing !== []) {
            $rest->requestExpecting('POST', RestTransport::path('schema', self::COLLECTION, 'tenants'), 'Create tenants', body: array_map(
                static fn(string $name): array => ['name' => $name],
                $missing,
            ));
        }

        $objects = [];
        foreach (self::OBJECTS as $tenant => $rows) {
            foreach ($rows as $uuid => [$title, $year, $vector]) {
                $objects[] = [
                    'class' => self::COLLECTION,
                    'tenant' => $tenant,
                    'id' => $uuid,
                    'properties' => ['title' => $title, 'year' => $year],
                    'vector' => $vector,
                ];
            }
        }
        $batch = $rest->requestExpecting('POST', '/batch/objects', 'Upsert fixture objects', body: ['objects' => $objects]);
        foreach (\is_array($batch->body) ? $batch->body : [] as $result) {
            $outcome = \is_array($result) ? ($result['result'] ?? null) : null;
            $errors = \is_array($outcome) ? ($outcome['errors'] ?? null) : null;
            self::assertNull($errors, 'fixture upsert failed: ' . json_encode($errors));
        }
    }

    /**
     * @param list<float>|null $nearVector
     */
    private function search(WeaviateClient $client, string $tenant, int $limit, ?array $nearVector = null): SearchReply
    {
        $request = new SearchRequest([
            'collection' => self::COLLECTION,
            'tenant' => $tenant,
            'limit' => $limit,
            'uses_127_api' => true,
            'properties' => new PropertiesRequest(['non_ref_properties' => ['title', 'year']]),
            'metadata' => new MetadataRequest(['uuid' => true, 'distance' => $nearVector !== null]),
        ]);
        if ($nearVector !== null) {
            $request->setNearVector(new NearVector(['vectors' => [new Vectors([
                'vector_bytes' => pack('g*', ...$nearVector),
                'type' => VectorType::VECTOR_TYPE_SINGLE_FP32,
            ])]]));
        }

        return $client->grpcTransport()->unary(self::SEARCH, $request, SearchReply::class, 30.0, $client->grpcMetadata());
    }

    /**
     * @return list<string>
     */
    private function titles(SearchReply $reply): array
    {
        $titles = [];
        foreach ($reply->getResults() as $result) {
            $value = $result->getProperties()?->getNonRefProps()?->getFields()['title'] ?? null;
            $titles[] = $value instanceof Value ? $value->getTextValue() : '';
        }
        sort($titles);

        return $titles;
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
