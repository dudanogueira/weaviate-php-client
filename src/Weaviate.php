<?php

declare(strict_types=1);

namespace Weaviate\Client;

use Weaviate\Client\Connect\AdditionalConfig;
use Weaviate\Client\Connect\Auth\AuthCredentials;
use Weaviate\Client\Connect\ConnectionParams;
use Weaviate\Client\Connect\ProtocolParams;
use Weaviate\Client\Exceptions\InvalidInputException;

/**
 * Connect helpers. Each builds a WeaviateClient, connects it, and returns it; if connecting fails the
 * client is closed before the exception is re-thrown. See docs/09-connection.md §2.
 */
final class Weaviate
{
    private function __construct() {}

    /**
     * A local instance (e.g. docker-compose). Always plaintext; the same host for REST and gRPC.
     *
     * @param array<string, string|null> $headers
     */
    public static function connectToLocal(
        string $host = 'localhost',
        int $port = 8080,
        int $grpcPort = 50051,
        array $headers = [],
        ?AdditionalConfig $additionalConfig = null,
        bool $skipInitChecks = false,
        string|AuthCredentials|null $auth = null,
    ): WeaviateClient {
        return self::connect(new WeaviateClient(
            new ConnectionParams(new ProtocolParams($host, $port, false), new ProtocolParams($host, $grpcPort, false)),
            $auth,
            $headers,
            $additionalConfig,
            $skipInitChecks,
        ));
    }

    /**
     * A Weaviate Cloud cluster: REST and gRPC on 443 with TLS; the gRPC host is derived from the cluster URL.
     *
     * @param array<string, string|null> $headers
     */
    public static function connectToWeaviateCloud(
        string $clusterUrl,
        string|AuthCredentials $auth,
        array $headers = [],
        ?AdditionalConfig $additionalConfig = null,
        bool $skipInitChecks = false,
    ): WeaviateClient {
        [$httpHost, $grpcHost] = self::parseCloudUrl($clusterUrl);

        return self::connect(new WeaviateClient(
            new ConnectionParams(new ProtocolParams($httpHost, 443, true), new ProtocolParams($grpcHost, 443, true)),
            $auth,
            $headers,
            $additionalConfig,
            $skipInitChecks,
        ));
    }

    /**
     * Any topology: separate host, port and TLS flag for REST and for gRPC.
     *
     * @param array<string, string|null> $headers
     */
    public static function connectToCustom(
        string $httpHost,
        int $httpPort,
        bool $httpSecure,
        string $grpcHost,
        int $grpcPort,
        bool $grpcSecure,
        array $headers = [],
        ?AdditionalConfig $additionalConfig = null,
        string|AuthCredentials|null $auth = null,
        bool $skipInitChecks = false,
        ?string $grpcPathPrefix = null,
    ): WeaviateClient {
        return self::connect(new WeaviateClient(
            ConnectionParams::fromParams($httpHost, $httpPort, $httpSecure, $grpcHost, $grpcPort, $grpcSecure, $grpcPathPrefix),
            $auth,
            $headers,
            $additionalConfig,
            $skipInitChecks,
        ));
    }

    /**
     * Python `__parse_weaviate_cloud_cluster_url`: accepts a bare host or a pasted URL.
     *
     * @internal
     *
     * @return array{string, string} [httpHost, grpcHost]
     */
    public static function parseCloudUrl(string $clusterUrl): array
    {
        $host = $clusterUrl;
        if (str_starts_with($clusterUrl, 'http')) {
            $host = (string) parse_url($clusterUrl, \PHP_URL_HOST);
        }
        $host = rtrim($host, '/');
        if ($host === '') {
            throw new InvalidInputException(\sprintf('Invalid cluster URL: %s', $clusterUrl));
        }

        if (str_ends_with($host, '.weaviate.network')) {
            [$ident, $domain] = explode('.', $host, 2);

            return [$host, $ident . '.grpc.' . $domain];
        }

        return [$host, 'grpc-' . $host];
    }

    private static function connect(WeaviateClient $client): WeaviateClient
    {
        try {
            $client->connect();
        } catch (\Throwable $e) {
            $client->close();

            throw $e;
        }

        return $client;
    }
}
