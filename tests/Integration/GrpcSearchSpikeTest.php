<?php

declare(strict_types=1);

namespace Weaviate\Client\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use Weaviate\Client\Connect\GrpcTransportChoice;
use Weaviate\Client\Exceptions\GrpcException;
use Weaviate\Client\Proto\V1\MetadataRequest;
use Weaviate\Client\Proto\V1\NearVector;
use Weaviate\Client\Proto\V1\PropertiesRequest;
use Weaviate\Client\Proto\V1\SearchReply;
use Weaviate\Client\Proto\V1\SearchRequest;
use Weaviate\Client\Proto\V1\SearchResult;
use Weaviate\Client\Proto\V1\Value;
use Weaviate\Client\Proto\V1\Vectors;
use Weaviate\Client\Proto\V1\Vectors\VectorType;
use Weaviate\Client\Transport\Grpc\ExtGrpcTransport;
use Weaviate\Client\Transport\Grpc\GrpcStatus;
use Weaviate\Client\WeaviateClient;

/**
 * ADR 0002 spike, exit criteria 1 and 2: unary Search works over each transport, and a non-OK grpc-status
 * maps to GrpcException. Uses raw protobuf messages; the typed query API comes in P2.
 */
final class GrpcSearchSpikeTest extends IntegrationTestCase
{
    private const SEARCH = '/weaviate.v1.Weaviate/Search';

    /**
     * @return iterable<string, array{GrpcTransportChoice}>
     */
    public static function transports(): iterable
    {
        yield 'curl' => [GrpcTransportChoice::Curl];
        yield 'ext-grpc' => [GrpcTransportChoice::ExtGrpc];
    }

    #[DataProvider('transports')]
    public function testFetchObjectsAndNearVector(GrpcTransportChoice $transport): void
    {
        $client = $this->connectWith($transport);
        $collection = self::uniqueName('Spike');
        $this->createCollectionWithObjects($client, $collection);

        try {
            $fetch = $this->search($client, new SearchRequest([
                'collection' => $collection,
                'limit' => 10,
                'uses_127_api' => true,
                'properties' => new PropertiesRequest(['return_all_nonref_properties' => true]),
                'metadata' => new MetadataRequest(['uuid' => true]),
            ]));
            self::assertCount(3, $fetch->getResults());
            $titles = [];
            foreach ($fetch->getResults() as $result) {
                $titles[] = self::title($result);
            }
            sort($titles);
            self::assertSame(['alpha', 'beta', 'gamma'], $titles);

            $near = $this->search($client, new SearchRequest([
                'collection' => $collection,
                'limit' => 1,
                'uses_127_api' => true,
                'near_vector' => new NearVector(['vectors' => [new Vectors([
                    'vector_bytes' => pack('g*', 0.0, 0.0, 1.0),
                    'type' => VectorType::VECTOR_TYPE_SINGLE_FP32,
                ])]]),
                'properties' => new PropertiesRequest(['return_all_nonref_properties' => true]),
                'metadata' => new MetadataRequest(['uuid' => true, 'distance' => true]),
            ]));
            self::assertCount(1, $near->getResults());
            $best = $near->getResults()[0];
            self::assertSame('gamma', self::title($best));
            self::assertEqualsWithDelta(0.0, $best->getMetadata()?->getDistance(), 1e-6);
        } finally {
            $client->restTransport()->request('DELETE', '/schema/' . $collection);
            $client->close();
        }
    }

    #[DataProvider('transports')]
    public function testNonOkStatusMapsToGrpcException(GrpcTransportChoice $transport): void
    {
        $client = $this->connectWith($transport);

        try {
            $this->search($client, new SearchRequest(['collection' => 'DoesNotExist' . bin2hex(random_bytes(4)), 'uses_127_api' => true]));
            self::fail('expected a GrpcException');
        } catch (GrpcException $e) {
            self::assertNotSame(GrpcStatus::Ok, $e->grpcStatus);
            self::assertStringContainsStringIgnoringCase('doesnotexist', $e->grpcMessage);
        } finally {
            $client->close();
        }
    }

    #[DataProvider('transports')]
    public function testConnectionIsReusedAcrossCalls(GrpcTransportChoice $transport): void
    {
        $client = $this->connectWith($transport);
        $collection = self::uniqueName('Spike');
        $this->createCollectionWithObjects($client, $collection);

        try {
            for ($i = 0; $i < 50; ++$i) {
                $reply = $this->search($client, new SearchRequest(['collection' => $collection, 'limit' => 3, 'uses_127_api' => true]));
                self::assertCount(3, $reply->getResults());
            }
        } finally {
            $client->restTransport()->request('DELETE', '/schema/' . $collection);
            $client->close();
        }
    }

    private static function title(SearchResult $result): ?string
    {
        $value = $result->getProperties()?->getNonRefProps()?->getFields()['title'] ?? null;

        return $value instanceof Value ? $value->getTextValue() : null;
    }

    private function connectWith(GrpcTransportChoice $transport): WeaviateClient
    {
        if ($transport === GrpcTransportChoice::ExtGrpc && !ExtGrpcTransport::isSupported()) {
            self::markTestSkipped('ext-grpc is not loaded');
        }

        $client = $this->connect($transport);
        self::assertSame($transport === GrpcTransportChoice::Curl ? 'curl' : 'ext-grpc', $client->grpcTransport()->name());

        return $client;
    }

    private function search(WeaviateClient $client, SearchRequest $request): SearchReply
    {
        return $client->grpcTransport()->unary(self::SEARCH, $request, SearchReply::class, 10.0, $client->grpcMetadata());
    }

    private function createCollectionWithObjects(WeaviateClient $client, string $collection): void
    {
        $rest = $client->restTransport();
        $rest->requestExpecting('POST', '/schema', 'Create collection', body: [
            'class' => $collection,
            'vectorizer' => 'none',
            'properties' => [['name' => 'title', 'dataType' => ['text']]],
        ]);
        foreach (['alpha' => [1.0, 0.0, 0.0], 'beta' => [0.0, 1.0, 0.0], 'gamma' => [0.0, 0.0, 1.0]] as $title => $vector) {
            $rest->requestExpecting('POST', '/objects', 'Insert object', body: [
                'class' => $collection,
                'properties' => ['title' => $title],
                'vector' => $vector,
            ]);
        }
    }
}
