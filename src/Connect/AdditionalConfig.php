<?php

declare(strict_types=1);

namespace Weaviate\Client\Connect;

use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Weaviate\Client\Transport\Grpc\GrpcTransport;

/**
 * Rarely-used connection settings. Python `AdditionalConfig`; see docs/09-connection.md §4.
 *
 * - `maxResponseBytes`: the largest REST body or gRPC message accepted (security review S2/S8).
 * - `proxies`: one proxy URL for REST and gRPC. When null, the environment (HTTP_PROXY, HTTPS_PROXY,
 *   grpc_proxy, …) is ignored unless `trustEnv` is true, like Python's `trust_env=False` default.
 *
 * P0 status: per-protocol Proxies, GrpcConfig (TLS/mTLS, channel options) and the connection pool land later.
 */
final readonly class AdditionalConfig
{
    /**
     * Ceiling for any single REST response body and gRPC message (256 MiB). The server's
     * `grpcMaxMessageSize` can lower the gRPC limit but not raise it above this.
     */
    public const DEFAULT_MAX_RESPONSE_BYTES = 268_435_456;

    public Timeout $timeout;

    /**
     * @param Timeout|array<int|float> $timeout [query, insert] like Python's tuple
     */
    public function __construct(
        Timeout|array $timeout = new Timeout(),
        #[\SensitiveParameter]
        public ?string $proxies = null,
        public GrpcTransportChoice|GrpcTransport $grpcTransport = GrpcTransportChoice::Auto,
        public ?ClientInterface $httpClient = null,
        public ?LoggerInterface $logger = null,
        public bool $trustEnv = false,
        public int $maxResponseBytes = self::DEFAULT_MAX_RESPONSE_BYTES,
    ) {
        $this->timeout = Timeout::from($timeout);
        if ($maxResponseBytes < 1024) {
            throw new \Weaviate\Client\Exceptions\InvalidInputException('maxResponseBytes must be at least 1024');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'timeout' => $this->timeout,
            'proxies' => $this->proxies === null ? null : '***',
            'grpcTransport' => $this->grpcTransport instanceof GrpcTransport ? $this->grpcTransport->name() : $this->grpcTransport->name,
            'trustEnv' => $this->trustEnv,
        ];
    }
}
