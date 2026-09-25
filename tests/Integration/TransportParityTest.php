<?php

declare(strict_types=1);

namespace Weaviate\Client\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use Weaviate\Client\Connect\ProtocolParams;
use Weaviate\Client\Exceptions\GrpcException;
use Weaviate\Client\Proto\V1\PropertiesRequest;
use Weaviate\Client\Proto\V1\SearchReply;
use Weaviate\Client\Proto\V1\SearchRequest;
use Weaviate\Client\Proto\V1\WeaviateHealthCheckRequest;
use Weaviate\Client\Proto\V1\WeaviateHealthCheckResponse;
use Weaviate\Client\Transport\Grpc\CurlGrpcTransport;
use Weaviate\Client\Transport\Grpc\ExtGrpcTransport;
use Weaviate\Client\Transport\Grpc\GrpcStatus;
use Weaviate\Client\Transport\Grpc\GrpcTransport;
use Weaviate\Client\Transport\Rest\RestTransport;

/**
 * QA F8: both transports report the same failure the same way (exception class and gRPC status).
 */
final class TransportParityTest extends IntegrationTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function transports(): iterable
    {
        yield 'curl' => ['curl'];
        yield 'ext-grpc' => ['ext-grpc'];
    }

    #[DataProvider('transports')]
    public function testClosedPortIsUnavailable(string $transport): void
    {
        $this->assertStatus(GrpcStatus::Unavailable, fn() => $this->health(self::make($transport, new ProtocolParams('127.0.0.1', 1, false)), 3.0));
    }

    #[DataProvider('transports')]
    public function testOversizedRequestIsResourceExhausted(string $transport): void
    {
        $grpc = self::make($transport, $this->endpoint(), maxMessageLength: 16);

        $this->assertStatus(GrpcStatus::ResourceExhausted, fn() => $grpc->unary(
            '/weaviate.v1.Weaviate/Search',
            new SearchRequest(['collection' => str_repeat('x', 64)]),
            SearchReply::class,
            5.0,
        ));
    }

    #[DataProvider('transports')]
    public function testOversizedResponseIsResourceExhausted(string $transport): void
    {
        $client = $this->connect();
        $collection = self::uniqueName('Parity');
        $rest = $client->restTransport();
        $rest->requestExpecting('POST', '/schema', 'create', body: ['class' => $collection, 'vectorizer' => 'none', 'properties' => [['name' => 'text', 'dataType' => ['text']]]]);
        $rest->requestExpecting('POST', '/objects', 'insert', body: ['class' => $collection, 'properties' => ['text' => str_repeat('z', 4096)], 'vector' => [1.0, 0.0]]);

        try {
            $grpc = self::make($transport, $this->endpoint(), maxMessageLength: 1024);
            $this->assertStatus(GrpcStatus::ResourceExhausted, fn() => $grpc->unary(
                '/weaviate.v1.Weaviate/Search',
                new SearchRequest(['collection' => $collection, 'limit' => 1, 'uses_127_api' => true, 'properties' => new PropertiesRequest(['non_ref_properties' => ['text']])]),
                SearchReply::class,
                5.0,
            ));
        } finally {
            $rest->request('DELETE', RestTransport::path('schema', $collection));
            $client->close();
        }
    }

    #[DataProvider('transports')]
    public function testUnknownMethodIsUnimplemented(string $transport): void
    {
        $this->connect()->close();
        $grpc = self::make($transport, $this->endpoint());

        $this->assertStatus(GrpcStatus::Unimplemented, fn() => $grpc->unary(
            '/weaviate.v1.Weaviate/DoesNotExist',
            new WeaviateHealthCheckRequest(),
            WeaviateHealthCheckResponse::class,
            5.0,
        ));
    }

    #[DataProvider('transports')]
    public function testServerThatNeverAnswersIsDeadlineExceeded(string $transport): void
    {
        // A listening socket that never reads: TCP connects, then nothing happens.
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($server, (string) $errstr);
        $port = (int) substr((string) stream_socket_get_name($server, false), strrpos((string) stream_socket_get_name($server, false), ':') + 1);

        $start = microtime(true);
        $this->assertStatus(
            GrpcStatus::DeadlineExceeded,
            fn() => $this->health(self::make($transport, new ProtocolParams('127.0.0.1', $port, false)), 0.5),
            // ext-grpc can't finish its HTTP/2 handshake, so it may report the channel as unavailable
            allowOther: $transport === 'ext-grpc' ? [GrpcStatus::Unavailable] : [],
        );
        self::assertLessThan(3.0, microtime(true) - $start, 'the deadline bounds the call');
        fclose($server);
    }

    private function endpoint(): ProtocolParams
    {
        return new ProtocolParams(self::host(), self::grpcPort(), false);
    }

    private static function make(string $transport, ProtocolParams $endpoint, int $maxMessageLength = CurlGrpcTransport::DEFAULT_MAX_MESSAGE_LENGTH): GrpcTransport
    {
        if ($transport === 'ext-grpc') {
            if (!ExtGrpcTransport::isSupported()) {
                self::markTestSkipped('ext-grpc is not loaded');
            }

            return new ExtGrpcTransport($endpoint, $maxMessageLength);
        }

        return new CurlGrpcTransport($endpoint, $maxMessageLength);
    }

    private function health(GrpcTransport $grpc, float $timeout): void
    {
        $grpc->unary('/grpc.health.v1.Health/Check', new WeaviateHealthCheckRequest(), WeaviateHealthCheckResponse::class, $timeout);
    }

    /**
     * @param list<GrpcStatus> $allowOther statuses accepted too (documented per call site)
     */
    private function assertStatus(GrpcStatus $expected, callable $call, array $allowOther = []): void
    {
        try {
            $call();
            self::fail('expected a GrpcException');
        } catch (GrpcException $e) {
            self::assertContains($e->grpcStatus, [$expected, ...$allowOther], $e->getMessage());
        }
    }
}
