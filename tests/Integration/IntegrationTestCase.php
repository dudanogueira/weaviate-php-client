<?php

declare(strict_types=1);

namespace Weaviate\Client\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Weaviate\Client\Connect\AdditionalConfig;
use Weaviate\Client\Connect\GrpcTransportChoice;
use Weaviate\Client\Weaviate;
use Weaviate\Client\WeaviateClient;

/**
 * Integration tests run against a real Weaviate (docker compose up -d weaviate).
 * WEAVIATE_HOST / WEAVIATE_HTTP_PORT / WEAVIATE_GRPC_PORT select the server.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected static function host(): string
    {
        return self::env('WEAVIATE_HOST', 'localhost');
    }

    protected static function httpPort(): int
    {
        return (int) self::env('WEAVIATE_HTTP_PORT', '8080');
    }

    protected static function grpcPort(): int
    {
        return (int) self::env('WEAVIATE_GRPC_PORT', '50051');
    }

    protected function connect(GrpcTransportChoice $transport = GrpcTransportChoice::Auto): WeaviateClient
    {
        $socket = @fsockopen(self::host(), self::httpPort(), $errno, $errstr, 1.0);
        if ($socket === false) {
            self::markTestSkipped(\sprintf('No Weaviate at %s:%d (%s)', self::host(), self::httpPort(), $errstr));
        }
        fclose($socket);

        return Weaviate::connectToLocal(
            host: self::host(),
            port: self::httpPort(),
            grpcPort: self::grpcPort(),
            additionalConfig: new AdditionalConfig(grpcTransport: $transport),
        );
    }

    protected static function uniqueName(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(4));
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return \is_string($value) && $value !== '' ? $value : $default;
    }
}
