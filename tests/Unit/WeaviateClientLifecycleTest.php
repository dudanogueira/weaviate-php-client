<?php

declare(strict_types=1);

namespace Weaviate\Client\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Weaviate\Client\Connect\AdditionalConfig;
use Weaviate\Client\Connect\ConnectionParams;
use Weaviate\Client\Exceptions\AuthenticationException;
use Weaviate\Client\Exceptions\ClientClosedException;
use Weaviate\Client\Exceptions\GrpcException;
use Weaviate\Client\Exceptions\InsufficientPermissionsException;
use Weaviate\Client\Exceptions\WeaviateStartUpException;
use Weaviate\Client\Proto\V1\WeaviateHealthCheckResponse;
use Weaviate\Client\Proto\V1\WeaviateHealthCheckResponse\ServingStatus;
use Weaviate\Client\Tests\Support\FakeGrpcTransport;
use Weaviate\Client\Tests\Support\FakeHttpClient;
use Weaviate\Client\Transport\Grpc\GrpcStatus;
use Weaviate\Client\WeaviateClient;

/**
 * The connect() sequence and lifecycle, driven by a fake PSR-18 client and a fake gRPC transport.
 */
final class WeaviateClientLifecycleTest extends TestCase
{
    private const HEALTH = '/grpc.health.v1.Health/Check';

    private static function client(FakeHttpClient $http, FakeGrpcTransport $grpc, bool $skipInitChecks = false): WeaviateClient
    {
        return new WeaviateClient(
            ConnectionParams::fromParams('localhost', 8080, false, 'localhost', 50051, false),
            auth: 'key',
            additionalConfig: new AdditionalConfig(httpClient: $http, grpcTransport: $grpc),
            skipInitChecks: $skipInitChecks,
        );
    }

    public function testConnectSequence(): void
    {
        $http = FakeHttpClient::weaviate('1.39.7');
        $grpc = FakeGrpcTransport::serving();
        $client = self::client($http, $grpc);

        $client->connect();

        self::assertTrue($client->isConnected());
        self::assertSame('1.39.7', (string) $client->serverVersion());
        self::assertSame(['GET /v1/meta'], array_map(static fn($r) => $r->getMethod() . ' ' . $r->getUri()->getPath(), $http->requests));
        self::assertSame(self::HEALTH, $grpc->calls[0]['method']);
        self::assertSame(2.0, $grpc->calls[0]['timeout'], 'the health check uses the init timeout');
        self::assertSame('Bearer key', $grpc->calls[0]['metadata']['authorization']);
        self::assertSame('Bearer key', $http->requests[0]->getHeaderLine('authorization'));
    }

    public function testServersBelowTheFloorAreRefusedEvenWithSkipInitChecks(): void
    {
        $client = self::client(FakeHttpClient::weaviate('1.28.4'), FakeGrpcTransport::serving(), skipInitChecks: true);

        $this->expectException(WeaviateStartUpException::class);
        $this->expectExceptionMessage('Weaviate version 1.28.4 is not supported');
        $client->connect();
    }

    public function testFloorVersionIsAccepted(): void
    {
        $client = self::client(FakeHttpClient::weaviate('1.29.0'), FakeGrpcTransport::serving());
        $client->connect();

        self::assertTrue($client->isConnected());
    }

    public function testNonWeaviateMetaGivesAClearError(): void
    {
        $http = new FakeHttpClient(['GET /v1/meta' => new Response(200, ['content-type' => 'text/html'], '<html>proxy login</html>')]);

        $this->expectException(WeaviateStartUpException::class);
        $this->expectExceptionMessage('did not return a Weaviate version');
        self::client($http, FakeGrpcTransport::serving())->connect();
    }

    public function testRestUnauthorizedIsAnAuthenticationException(): void
    {
        $http = new FakeHttpClient(['GET /v1/meta' => FakeHttpClient::json(['error' => [['message' => 'invalid api key']]], 401)]);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('invalid api key');
        self::client($http, FakeGrpcTransport::serving())->connect();
    }

    public function testGrpcAuthFailuresAreNotReportedAsUnreachablePorts(): void
    {
        $grpc = (new FakeGrpcTransport())->respond(self::HEALTH, new AuthenticationException('bad key'));

        $this->expectException(AuthenticationException::class);
        self::client(FakeHttpClient::weaviate(), $grpc)->connect();
    }

    public function testHealthCheckFailureIsAStartupError(): void
    {
        $grpc = (new FakeGrpcTransport())->respond(self::HEALTH, new GrpcException(self::HEALTH, GrpcStatus::Unavailable, 'connection refused'));

        $this->expectException(WeaviateStartUpException::class);
        $this->expectExceptionMessage('Check that the gRPC host and port are right');
        self::client(FakeHttpClient::weaviate(), $grpc)->connect();
    }

    public function testNotServingIsAStartupError(): void
    {
        $grpc = (new FakeGrpcTransport())->respond(self::HEALTH, new WeaviateHealthCheckResponse(['status' => ServingStatus::NOT_SERVING]));

        $this->expectException(WeaviateStartUpException::class);
        $this->expectExceptionMessage('expected SERVING');
        self::client(FakeHttpClient::weaviate(), $grpc)->connect();
    }

    public function testSkipInitChecksSkipsOnlyTheHealthCheck(): void
    {
        $http = FakeHttpClient::weaviate();
        $grpc = new FakeGrpcTransport(); // would throw if called
        $client = self::client($http, $grpc, skipInitChecks: true);

        $client->connect();

        self::assertTrue($client->isConnected());
        self::assertSame([], $grpc->calls);
        self::assertCount(1, $http->requests, '/v1/meta still runs');
    }

    public function testFailedForcedReconnectLeavesTheClientDisconnected(): void
    {
        $http = FakeHttpClient::weaviate();
        $grpc = FakeGrpcTransport::serving();
        $client = self::client($http, $grpc);
        $client->connect();

        $http->route('GET /v1/meta', new Response(503, [], 'maintenance'));
        try {
            $client->connect(force: true);
            self::fail('expected the reconnect to fail');
        } catch (\Throwable) {
        }

        self::assertFalse($client->isConnected());
        $this->expectException(ClientClosedException::class);
        $client->serverVersion();
    }

    public function testConnectIsIdempotentWithoutForce(): void
    {
        $http = FakeHttpClient::weaviate();
        $client = self::client($http, FakeGrpcTransport::serving());

        $client->connect();
        $client->connect();

        self::assertCount(1, $http->requests);
    }

    public function testCloseClearsStateAndLeavesInjectedTransportsOpen(): void
    {
        $grpc = FakeGrpcTransport::serving();
        $client = self::client(FakeHttpClient::weaviate(), $grpc);
        $client->connect();

        $client->close();

        self::assertFalse($client->isConnected());
        self::assertSame(0, $grpc->closeCount, 'a transport the caller injected is theirs to close');
        try {
            $client->serverVersion();
            self::fail('serverVersion() must not be stale after close()');
        } catch (ClientClosedException) {
        }
        $this->expectException(ClientClosedException::class);
        $client->restTransport();
    }

    public function testIsReadyAndIsLiveReturnFalseInsteadOfThrowing(): void
    {
        $http = FakeHttpClient::weaviate();
        $grpc = FakeGrpcTransport::serving();
        $client = self::client($http, $grpc);

        self::assertFalse($client->isReady(), 'not connected yet');
        self::assertFalse($client->isLive(), 'not connected yet');

        $client->connect();
        self::assertTrue($client->isReady());
        self::assertTrue($client->isLive());

        $grpc->respond(self::HEALTH, new GrpcException(self::HEALTH, GrpcStatus::Unavailable, 'down'));
        self::assertFalse($client->isLive(), 'isLive includes the gRPC health check');

        $http->route('GET /v1/.well-known/ready', new Response(503));
        self::assertFalse($client->isReady());
    }

    public function testRestStatusMapping(): void
    {
        $http = FakeHttpClient::weaviate()
            ->route('POST /v1/schema', FakeHttpClient::json(['error' => [['message' => 'forbidden: norole']]], 403))
            ->route('DELETE /v1/schema/X', FakeHttpClient::json(['errorCode' => 'USAGE_LIMIT_EXCEEDED', 'message' => 'collections count limit of 1 reached'], 429));
        $client = self::client($http, FakeGrpcTransport::serving());
        $client->connect();
        $rest = $client->restTransport();

        try {
            $rest->requestExpecting('POST', '/schema', 'Create collection', body: ['class' => 'X']);
            self::fail('expected 403');
        } catch (InsufficientPermissionsException $e) {
            self::assertSame(403, $e->statusCode);
            self::assertStringContainsString('forbidden: norole', $e->getMessage());
        }

        try {
            $rest->requestExpecting('DELETE', '/schema/X', 'Delete collection');
            self::fail('expected 429');
        } catch (\Weaviate\Client\Exceptions\UsageLimitException $e) {
            self::assertSame('USAGE_LIMIT_EXCEEDED', $e->errorCode());
            self::assertStringContainsString('collections count limit', $e->getMessage());
        }
    }
}
