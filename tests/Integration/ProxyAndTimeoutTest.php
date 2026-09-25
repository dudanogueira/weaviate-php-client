<?php

declare(strict_types=1);

namespace Weaviate\Client\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use Weaviate\Client\Connect\AdditionalConfig;
use Weaviate\Client\Connect\GrpcTransportChoice;
use Weaviate\Client\Connect\Timeout;
use Weaviate\Client\Exceptions\WeaviateStartUpException;
use Weaviate\Client\Transport\Grpc\ExtGrpcTransport;
use Weaviate\Client\Weaviate;

/**
 * QA F4/F5: proxy environment variables are ignored unless trustEnv is set, explicit proxies are honoured by
 * every transport, and connect() is bounded by the init timeout.
 */
final class ProxyAndTimeoutTest extends IntegrationTestCase
{
    /** Nothing listens on port 9 (discard), so any request routed through this "proxy" fails fast. */
    private const DEAD_PROXY = 'http://127.0.0.1:9';

    private const PROXY_VARS = ['http_proxy', 'HTTP_PROXY', 'https_proxy', 'HTTPS_PROXY', 'all_proxy', 'ALL_PROXY', 'grpc_proxy'];

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        $this->savedEnv = [];
    }

    /**
     * @return iterable<string, array{GrpcTransportChoice}>
     */
    public static function transports(): iterable
    {
        yield 'curl' => [GrpcTransportChoice::Curl];
        yield 'ext-grpc' => [GrpcTransportChoice::ExtGrpc];
    }

    #[DataProvider('transports')]
    public function testProxyEnvironmentIsIgnoredByDefault(GrpcTransportChoice $transport): void
    {
        $this->skipUnlessAvailable($transport);
        $this->connect()->close();
        $this->setProxyEnv(self::DEAD_PROXY);

        $client = $this->connectWith($transport);

        self::assertTrue($client->isLive(), 'REST and gRPC went direct despite the proxy environment');
        $client->close();
    }

    #[DataProvider('transports')]
    public function testTrustEnvRoutesThroughTheEnvironmentProxy(GrpcTransportChoice $transport): void
    {
        $this->skipUnlessAvailable($transport);
        $this->connect()->close();
        $this->setProxyEnv(self::DEAD_PROXY);

        $this->expectException(WeaviateStartUpException::class);
        $this->connectWith($transport, new AdditionalConfig(timeout: [3, 3], grpcTransport: $transport, trustEnv: true));
    }

    #[DataProvider('transports')]
    public function testExplicitProxyIsHonoured(GrpcTransportChoice $transport): void
    {
        $this->skipUnlessAvailable($transport);
        $this->connect()->close();

        $this->expectException(WeaviateStartUpException::class);
        $this->connectWith($transport, new AdditionalConfig(timeout: [3, 3], proxies: self::DEAD_PROXY, grpcTransport: $transport));
    }

    public function testConnectToAHungServerIsBoundedByTheInitTimeout(): void
    {
        // A listening socket that accepts the TCP connection (via the backlog) but never answers.
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($server, (string) $errstr);
        $name = (string) stream_socket_get_name($server, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);

        $start = microtime(true);
        try {
            Weaviate::connectToLocal(
                host: '127.0.0.1',
                port: $port,
                grpcPort: $port + 1 > 65535 ? 1 : $port + 1,
                additionalConfig: new AdditionalConfig(timeout: new Timeout(query: 30, insert: 30, init: 1)),
            );
            self::fail('expected a startup failure');
        } catch (WeaviateStartUpException) {
        } finally {
            fclose($server);
        }

        self::assertLessThan(2.5, microtime(true) - $start, '/v1/meta must use the init timeout, not query/insert');
    }

    private function skipUnlessAvailable(GrpcTransportChoice $transport): void
    {
        if ($transport === GrpcTransportChoice::ExtGrpc && !ExtGrpcTransport::isSupported()) {
            self::markTestSkipped('ext-grpc is not loaded');
        }
    }

    private function connectWith(GrpcTransportChoice $transport, ?AdditionalConfig $config = null): \Weaviate\Client\WeaviateClient
    {
        return Weaviate::connectToLocal(
            host: self::host(),
            port: self::httpPort(),
            grpcPort: self::grpcPort(),
            additionalConfig: $config ?? new AdditionalConfig(grpcTransport: $transport),
        );
    }

    private function setProxyEnv(string $proxy): void
    {
        foreach (self::PROXY_VARS as $name) {
            $this->savedEnv[$name] = getenv($name);
            putenv($name . '=' . $proxy);
        }
        foreach (['no_proxy', 'NO_PROXY'] as $name) {
            $this->savedEnv[$name] = getenv($name);
            putenv($name);
        }
    }
}
