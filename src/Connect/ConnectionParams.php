<?php

declare(strict_types=1);

namespace Weaviate\Client\Connect;

use Weaviate\Client\Exceptions\InvalidInputException;

/**
 * The REST and gRPC endpoints as two independent endpoints, each with its own host, port and TLS flag.
 * Python `ConnectionParams`; see docs/09-connection.md §1.
 */
final readonly class ConnectionParams
{
    /**
     * @param string|null $grpcPathPrefix grpc-web path prefix (e.g. "/v1/grpc-web"); null or "" means native gRPC
     */
    public function __construct(
        public ProtocolParams $http,
        public ProtocolParams $grpc,
        public ?string $grpcPathPrefix = null,
    ) {
        $sameEndpoint = $http->host === $grpc->host && $http->port === $grpc->port;
        if ($sameEndpoint && $this->grpcWebPathPrefix() === '') {
            throw new InvalidInputException('http port and grpc port must be different if using the same host');
        }
    }

    public static function fromParams(
        string $httpHost,
        int $httpPort,
        bool $httpSecure,
        string $grpcHost,
        int $grpcPort,
        bool $grpcSecure,
        ?string $grpcPathPrefix = null,
    ): self {
        return new self(
            new ProtocolParams($httpHost, $httpPort, $httpSecure),
            new ProtocolParams($grpcHost, $grpcPort, $grpcSecure),
            $grpcPathPrefix,
        );
    }

    /**
     * Python `from_url`: the host comes from the URL, the HTTP port from the URL or 443/80,
     * and gRPC is secure when $grpcSecure is set or the scheme is https.
     */
    public static function fromUrl(string $url, int $grpcPort, bool $grpcSecure = false, ?string $grpcPathPrefix = null): self
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? '';
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new InvalidInputException(\sprintf('Unsupported scheme: %s', $scheme));
        }
        $host = $parts['host'] ?? '';
        $https = $scheme === 'https';

        return new self(
            new ProtocolParams($host, $parts['port'] ?? ($https ? 443 : 80), $https),
            new ProtocolParams($host, $grpcPort, $grpcSecure || $https),
            $grpcPathPrefix,
        );
    }

    /**
     * Normalized grpc-web prefix: one leading slash, no trailing slash; "" means native gRPC.
     */
    public function grpcWebPathPrefix(): string
    {
        $cleaned = trim($this->grpcPathPrefix ?? '', '/');

        return $cleaned === '' ? '' : '/' . $cleaned;
    }

    public function httpUrl(): string
    {
        return \sprintf('%s://%s:%d', $this->http->secure ? 'https' : 'http', $this->http->host, $this->http->port);
    }

    public function grpcUrl(): string
    {
        return \sprintf('%s://%s:%d', $this->grpc->secure ? 'https' : 'http', $this->grpc->host, $this->grpc->port);
    }
}
