<?php

declare(strict_types=1);

namespace Weaviate\Client\Connect;

use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Weaviate\Client\Transport\Grpc\GrpcTransport;

/**
 * Rarely-used connection settings. Python `AdditionalConfig`; see docs/09-connection.md §4.
 *
 * P0 status: timeout, proxy (one URL for every protocol), transport choice, PSR-18 client and PSR-3 logger.
 * Per-protocol proxies, trustEnv, GrpcConfig (TLS/mTLS, channel options), the connection pool and retries
 * land later in P0.
 */
final readonly class AdditionalConfig
{
    public Timeout $timeout;

    /**
     * @param Timeout|array<int|float> $timeout [query, insert] like Python's tuple
     */
    public function __construct(
        Timeout|array $timeout = new Timeout(),
        public ?string $proxies = null,
        public GrpcTransportChoice|GrpcTransport $grpcTransport = GrpcTransportChoice::Auto,
        public ?ClientInterface $httpClient = null,
        public ?LoggerInterface $logger = null,
    ) {
        $this->timeout = Timeout::from($timeout);
    }
}
