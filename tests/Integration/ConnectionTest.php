<?php

declare(strict_types=1);

namespace Weaviate\Client\Tests\Integration;

use Weaviate\Client\Connect\AdditionalConfig;
use Weaviate\Client\Exceptions\WeaviateStartUpException;
use Weaviate\Client\Version;
use Weaviate\Client\Weaviate;

final class ConnectionTest extends IntegrationTestCase
{
    public function testConnectToLocalRunsTheStartupChecks(): void
    {
        $client = $this->connect();

        self::assertTrue($client->isConnected());
        self::assertTrue($client->isReady());
        self::assertTrue($client->isLive());
        self::assertTrue($client->serverVersion()->isAtLeastVersion(Version::MIN_SERVER));
        self::assertArrayHasKey('version', $client->getMeta());

        $client->close();
        self::assertFalse($client->isConnected());
    }

    public function testWrongGrpcPortFailsTheHealthCheck(): void
    {
        $this->connect()->close(); // skips when there is no server

        $this->expectException(WeaviateStartUpException::class);
        $this->expectExceptionMessage('gRPC health check');
        Weaviate::connectToLocal(
            host: self::host(),
            port: self::httpPort(),
            grpcPort: 1,
            additionalConfig: new AdditionalConfig(timeout: [5, 5]),
        );
    }

    public function testSkipInitChecksSkipsTheGrpcHealthCheck(): void
    {
        $this->connect()->close();

        $client = Weaviate::connectToLocal(host: self::host(), port: self::httpPort(), grpcPort: 1, skipInitChecks: true);

        self::assertTrue($client->isConnected());
        self::assertFalse($client->isLive(), 'isLive also pings gRPC, which is unreachable on port 1');
    }
}
