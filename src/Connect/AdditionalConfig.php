<?php

declare(strict_types=1);

namespace Weaviate\Client\Connect;

use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Weaviate\Client\Transport\Grpc\GrpcTransport;

/**
 * Rarely-used connection settings. Python `AdditionalConfig`; see docs/09-connection.md §4.
 *
 * - `proxies`: one proxy URL for REST and gRPC. When null, the environment (HTTP_PROXY, HTTPS_PROXY,
 *   grpc_proxy, …) is ignored unless `trustEnv` is true, like Python's `trust_env=False` default.
 *
 * P0 status: per-protocol Proxies, GrpcConfig (TLS/mTLS, channel options) and the connection pool land later.
 */
final readonly class AdditionalConfig
{
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
    ) {
        $this->timeout = Timeout::from($timeout);
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
