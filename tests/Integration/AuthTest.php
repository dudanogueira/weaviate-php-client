<?php

declare(strict_types=1);

namespace Weaviate\Client\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Weaviate\Client\Connect\AdditionalConfig;
use Weaviate\Client\Connect\GrpcTransportChoice;
use Weaviate\Client\Exceptions\AuthenticationException;
use Weaviate\Client\Exceptions\InsufficientPermissionsException;
use Weaviate\Client\Proto\V1\SearchReply;
use Weaviate\Client\Proto\V1\SearchRequest;
use Weaviate\Client\Transport\Grpc\ExtGrpcTransport;
use Weaviate\Client\Transport\Rest\RestTransport;
use Weaviate\Client\Weaviate;
use Weaviate\Client\WeaviateClient;

/**
 * QA F9: 401 / 403 on REST and UNAUTHENTICATED / PERMISSION_DENIED on gRPC map to AuthenticationException and
 * InsufficientPermissionsException, on both transports. Needs the RBAC server:
 *
 *   docker compose --profile auth up -d weaviate-auth
 *   bin/php vendor/bin/phpunit --group auth
 */
#[Group('auth')]
final class AuthTest extends TestCase
{
    private const ADMIN_KEY = 'admin-key';
    private const NOROLE_KEY = 'norole-key';

    /**
     * @return iterable<string, array{GrpcTransportChoice}>
     */
    public static function transports(): iterable
    {
        yield 'curl' => [GrpcTransportChoice::Curl];
        yield 'ext-grpc' => [GrpcTransportChoice::ExtGrpc];
    }

    public function testNoCredentialsIsAnAuthenticationError(): void
    {
        $this->expectException(AuthenticationException::class);
        self::connect(null);
    }

    public function testWrongKeyIsAnAuthenticationError(): void
    {
        $this->expectException(AuthenticationException::class);
        self::connect('not-a-key');
    }

    public function testRestForbiddenIsInsufficientPermissions(): void
    {
        $client = self::connect(self::NOROLE_KEY);

        try {
            $client->restTransport()->requestExpecting('POST', '/schema', 'Create collection', body: ['class' => 'AuthTestForbidden']);
            self::fail('expected 403');
        } catch (InsufficientPermissionsException $e) {
            self::assertSame(403, $e->statusCode);
        } finally {
            $client->close();
        }
    }

    #[DataProvider('transports')]
    public function testGrpcPermissionDeniedIsInsufficientPermissions(GrpcTransportChoice $transport): void
    {
        $admin = self::connect(self::ADMIN_KEY, $transport);
        $collection = 'AuthTest_' . bin2hex(random_bytes(4));
        $admin->restTransport()->requestExpecting('POST', '/schema', 'create', body: ['class' => $collection, 'vectorizer' => 'none']);
        $norole = self::connect(self::NOROLE_KEY, $transport);

        try {
            $norole->grpcTransport()->unary('/weaviate.v1.Weaviate/Search', new SearchRequest(['collection' => $collection, 'uses_127_api' => true]), SearchReply::class, 10.0, $norole->grpcMetadata());
            self::fail('expected PERMISSION_DENIED');
        } catch (InsufficientPermissionsException $e) {
            self::assertSame(403, $e->statusCode);
        } finally {
            $admin->restTransport()->request('DELETE', RestTransport::path('schema', $collection));
            $admin->close();
            $norole->close();
        }
    }

    #[DataProvider('transports')]
    public function testGrpcWithoutCredentialsIsUnauthenticated(GrpcTransportChoice $transport): void
    {
        $admin = self::connect(self::ADMIN_KEY, $transport);
        $metadata = $admin->grpcMetadata();
        unset($metadata['authorization']);

        try {
            $admin->grpcTransport()->unary('/weaviate.v1.Weaviate/Search', new SearchRequest(['collection' => 'Anything', 'uses_127_api' => true]), SearchReply::class, 10.0, $metadata);
            self::fail('expected UNAUTHENTICATED');
        } catch (AuthenticationException) {
            $this->addToAssertionCount(1);
        } finally {
            $admin->close();
        }
    }

    private static function connect(?string $apiKey, GrpcTransportChoice $transport = GrpcTransportChoice::Curl): WeaviateClient
    {
        if ($transport === GrpcTransportChoice::ExtGrpc && !ExtGrpcTransport::isSupported()) {
            self::markTestSkipped('ext-grpc is not loaded');
        }
        $host = getenv('WEAVIATE_AUTH_HOST') ?: 'localhost';
        $http = (int) (getenv('WEAVIATE_AUTH_HTTP_PORT') ?: 8081);
        $grpc = (int) (getenv('WEAVIATE_AUTH_GRPC_PORT') ?: 50052);
        $socket = @fsockopen($host, $http, $errno, $errstr, 1.0);
        if ($socket === false) {
            self::markTestSkipped(\sprintf('No RBAC Weaviate at %s:%d; run: docker compose --profile auth up -d weaviate-auth', $host, $http));
        }
        fclose($socket);

        return Weaviate::connectToLocal(
            host: $host,
            port: $http,
            grpcPort: $grpc,
            additionalConfig: new AdditionalConfig(grpcTransport: $transport),
            auth: $apiKey,
        );
    }
}
