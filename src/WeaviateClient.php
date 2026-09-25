<?php

declare(strict_types=1);

namespace Weaviate\Client;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Weaviate\Client\Connect\AdditionalConfig;
use Weaviate\Client\Connect\Auth;
use Weaviate\Client\Connect\Auth\ApiKey;
use Weaviate\Client\Connect\Auth\AuthCredentials;
use Weaviate\Client\Connect\ConnectionParams;
use Weaviate\Client\Connect\GrpcTransportChoice;
use Weaviate\Client\Exceptions\ConnectionException;
use Weaviate\Client\Exceptions\GrpcException;
use Weaviate\Client\Exceptions\InvalidInputException;
use Weaviate\Client\Exceptions\WeaviateException;
use Weaviate\Client\Exceptions\WeaviateStartUpException;
use Weaviate\Client\Proto\V1\WeaviateHealthCheckRequest;
use Weaviate\Client\Proto\V1\WeaviateHealthCheckResponse;
use Weaviate\Client\Proto\V1\WeaviateHealthCheckResponse\ServingStatus;
use Weaviate\Client\Transport\Grpc\CurlGrpcTransport;
use Weaviate\Client\Transport\Grpc\ExtGrpcTransport;
use Weaviate\Client\Transport\Grpc\GrpcTransport;
use Weaviate\Client\Transport\Rest\RestTransport;

/**
 * A connection to one Weaviate instance. Build it with the Weaviate::connectTo*() helpers, or construct it
 * and call connect(). The constructor does no I/O. See docs/09-connection.md.
 */
final class WeaviateClient
{
    private const HEALTH_METHOD = '/grpc.health.v1.Health/Check';

    private readonly AdditionalConfig $config;
    private readonly LoggerInterface $logger;
    private readonly ?AuthCredentials $auth;

    /** @var array<string, string> REST headers, lowercase names */
    private array $headers;

    private ?RestTransport $rest = null;
    private ?GrpcTransport $grpc = null;
    private ?ServerVersion $serverVersion = null;
    private bool $connected = false;

    /**
     * @param array<string, string|null> $headers extra headers, e.g. provider API keys ("X-OpenAI-Api-Key")
     */
    public function __construct(
        private readonly ConnectionParams $connectionParams,
        string|AuthCredentials|null $auth = null,
        array $headers = [],
        ?AdditionalConfig $additionalConfig = null,
        private readonly bool $skipInitChecks = false,
    ) {
        $this->config = $additionalConfig ?? new AdditionalConfig();
        $this->logger = $this->config->logger ?? new NullLogger();
        $this->auth = Auth::parse($auth);
        $this->headers = $this->buildHeaders($headers);
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Opens the connections and runs the startup sequence (docs/09-connection.md §7).
     *
     * @throws WeaviateStartUpException
     */
    public function connect(bool $force = false): void
    {
        if ($this->connected && !$force) {
            return;
        }

        $this->rest = new RestTransport(
            $this->connectionParams->httpUrl(),
            $this->headers,
            $this->config->timeout,
            $this->config->httpClient,
            $this->config->proxies,
        );

        // /v1/meta always runs, even with skipInitChecks: it gives the version and the gRPC message limit.
        try {
            $meta = $this->fetchMeta();
        } catch (ConnectionException $e) {
            throw new WeaviateStartUpException('Could not connect to Weaviate: ' . $e->getMessage(), 0, $e);
        }
        $this->serverVersion = ServerVersion::parse(\is_string($meta['version'] ?? null) ? $meta['version'] : '');

        $this->grpc = $this->createGrpcTransport();
        if (isset($meta['grpcMaxMessageSize']) && is_numeric($meta['grpcMaxMessageSize'])) {
            $size = (int) $meta['grpcMaxMessageSize'];
            if ($this->grpc instanceof CurlGrpcTransport || $this->grpc instanceof ExtGrpcTransport) {
                $this->grpc->setMaxMessageLength($size);
            }
        }

        if (!$this->serverVersion->isAtLeastVersion(Version::MIN_SERVER)) {
            throw new WeaviateStartUpException(\sprintf(
                'Weaviate version %s is not supported. Please use Weaviate version %s or higher.',
                $this->serverVersion->raw !== '' ? $this->serverVersion->raw : 'unknown',
                Version::MIN_SERVER,
            ));
        }

        if (!$this->skipInitChecks) {
            $this->pingGrpc();
        }

        $this->connected = true;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * `GET /v1/.well-known/ready`. Returns false on connection errors instead of throwing.
     */
    public function isReady(): bool
    {
        try {
            return $this->restTransport()->request('GET', '/.well-known/ready')->isSuccessful();
        } catch (WeaviateException) {
            return false;
        }
    }

    /**
     * `GET /v1/.well-known/live` and then the gRPC health check; true only when both pass.
     */
    public function isLive(): bool
    {
        try {
            if (!$this->restTransport()->request('GET', '/.well-known/live')->isSuccessful()) {
                return false;
            }
            $this->pingGrpc();

            return true;
        } catch (WeaviateException) {
            return false;
        }
    }

    /**
     * `GET /v1/meta`: the server version, hostname and enabled modules.
     *
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        return $this->fetchMeta();
    }

    public function serverVersion(): ServerVersion
    {
        return $this->serverVersion ?? throw new ConnectionException('Not connected: call connect() first');
    }

    public function close(): void
    {
        $this->grpc?->close();
        $this->grpc = null;
        $this->rest = null;
        $this->connected = false;
    }

    /**
     * @internal
     */
    public function restTransport(): RestTransport
    {
        return $this->rest ?? throw new ConnectionException('Not connected: call connect() first');
    }

    /**
     * @internal
     */
    public function grpcTransport(): GrpcTransport
    {
        return $this->grpc ?? throw new ConnectionException('Not connected: call connect() first');
    }

    /**
     * gRPC metadata: X-Weaviate-Client, user headers and authorization (docs/09-connection.md §5).
     *
     * @internal
     *
     * @return array<string, string>
     */
    public function grpcMetadata(): array
    {
        $metadata = [];
        foreach ($this->headers as $name => $value) {
            if ($name === 'content-type') {
                continue;
            }
            $metadata[$name] = $value;
        }

        return $metadata;
    }

    /**
     * @internal
     */
    public function timeouts(): Connect\Timeout
    {
        return $this->config->timeout;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchMeta(): array
    {
        $response = $this->restTransport()->requestExpecting('GET', '/meta', 'Get meta');
        /** @var array<string, mixed> $meta a JSON object decodes to string keys */
        $meta = \is_array($response->body) ? $response->body : [];

        return $meta;
    }

    private function pingGrpc(): void
    {
        try {
            $response = $this->grpcTransport()->unary(
                self::HEALTH_METHOD,
                new WeaviateHealthCheckRequest(),
                WeaviateHealthCheckResponse::class,
                (float) $this->config->timeout->init,
                $this->grpcMetadata(),
            );
        } catch (GrpcException|ConnectionException $e) {
            throw new WeaviateStartUpException(\sprintf(
                'The gRPC health check against %s failed. Is the gRPC port open? %s',
                $this->connectionParams->grpcUrl(),
                $e->getMessage(),
            ), 0, $e);
        }

        if ($response->getStatus() !== ServingStatus::SERVING) {
            throw new WeaviateStartUpException(\sprintf(
                'The gRPC health check against %s returned status %s (expected SERVING)',
                $this->connectionParams->grpcUrl(),
                (string) $response->getStatus(),
            ));
        }
    }

    private function createGrpcTransport(): GrpcTransport
    {
        $choice = $this->config->grpcTransport;
        if ($choice instanceof GrpcTransport) {
            return $choice;
        }
        if ($this->connectionParams->grpcWebPathPrefix() !== '') {
            throw new ConnectionException('grpc-web (grpcPathPrefix) is not implemented yet');
        }

        $endpoint = $this->connectionParams->grpc;
        $userAgent = 'weaviate-client-php/' . Version::CLIENT;

        return match ($choice) {
            GrpcTransportChoice::ExtGrpc => new ExtGrpcTransport($endpoint),
            GrpcTransportChoice::Curl => new CurlGrpcTransport($endpoint, connectTimeout: (float) $this->config->timeout->init, proxy: $this->config->proxies, userAgent: $userAgent),
            GrpcTransportChoice::Auto => match (true) {
                ExtGrpcTransport::isSupported() => new ExtGrpcTransport($endpoint),
                CurlGrpcTransport::isSupported() => new CurlGrpcTransport($endpoint, connectTimeout: (float) $this->config->timeout->init, proxy: $this->config->proxies, userAgent: $userAgent),
                default => throw new ConnectionException(
                    'No gRPC transport available: install ext-grpc, or use a libcurl built with HTTP/2 (nghttp2).',
                ),
            },
        };
    }

    /**
     * @param array<string, string|null> $userHeaders
     *
     * @return array<string, string>
     */
    private function buildHeaders(array $userHeaders): array
    {
        $headers = ['x-weaviate-client' => 'weaviate-client-php/' . Version::CLIENT . '-sync'];

        $host = strtolower($this->connectionParams->http->host);
        if (str_contains($host, 'weaviate.io') || str_contains($host, 'weaviate.cloud') || str_contains($host, 'semi.technology')) {
            $headers['x-weaviate-cluster-url'] = 'https://' . $this->connectionParams->http->host;
        }

        foreach ($userHeaders as $name => $value) {
            if ($value === null) {
                throw new InvalidInputException(\sprintf("Value for key '%s' in headers cannot be null.", $name));
            }
            $headers[strtolower($name)] = $value;
        }

        if (isset($headers['authorization']) && $this->auth !== null) {
            $this->logger->warning('Both an Authorization header and auth credentials were given; the header is ignored.');
            unset($headers['authorization']);
        }
        if ($this->auth instanceof ApiKey) {
            $headers['authorization'] = $this->auth->authorizationHeader();
        }

        return $headers;
    }
}
