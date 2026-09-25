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
use Weaviate\Client\Connect\Headers;
use Weaviate\Client\Exceptions\AuthenticationException;
use Weaviate\Client\Exceptions\ClientClosedException;
use Weaviate\Client\Exceptions\ConnectionException;
use Weaviate\Client\Exceptions\GrpcException;
use Weaviate\Client\Exceptions\InsufficientPermissionsException;
use Weaviate\Client\Exceptions\WeaviateException;
use Weaviate\Client\Exceptions\WeaviateStartUpException;
use Weaviate\Client\Proto\V1\WeaviateHealthCheckRequest;
use Weaviate\Client\Proto\V1\WeaviateHealthCheckResponse;
use Weaviate\Client\Proto\V1\WeaviateHealthCheckResponse\ServingStatus;
use Weaviate\Client\Transport\Grpc\CurlGrpcTransport;
use Weaviate\Client\Transport\Grpc\ExtGrpcTransport;
use Weaviate\Client\Transport\Grpc\GrpcTransport;
use Weaviate\Client\Transport\Rest\RestTransport;
use Weaviate\Client\Transport\Rest\TimeoutClass;

/**
 * A connection to one Weaviate instance. Build it with the Weaviate::connectTo*() helpers, or construct it
 * and call connect(). The constructor does no I/O. See docs/09-connection.md.
 *
 * var_dump()/print_r() output is redacted (credentials and provider keys are shown as ***).
 */
final class WeaviateClient
{
    private const HEALTH_METHOD = '/grpc.health.v1.Health/Check';

    /** Minimum health-check deadline when every gRPC call pays a fresh TLS handshake (spike 0001, finding 7). */
    private const FRESH_TLS_HEALTH_TIMEOUT = 5.0;

    private readonly AdditionalConfig $config;
    private readonly LoggerInterface $logger;
    private readonly ?AuthCredentials $auth;

    /** @var array<string, string> REST headers and gRPC metadata, lowercase names */
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
        #[\SensitiveParameter]
        string|AuthCredentials|null $auth = null,
        #[\SensitiveParameter]
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
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'http' => $this->connectionParams->httpUrl(),
            'grpc' => $this->connectionParams->grpcUrl(),
            'connected' => $this->connected,
            'serverVersion' => $this->serverVersion === null ? null : (string) $this->serverVersion,
            'grpcTransport' => $this->grpc?->name(),
            'headers' => Headers::redact($this->headers),
        ];
    }

    /**
     * Opens the connections and runs the startup sequence (docs/09-connection.md §7).
     *
     * `$force` (a PHP addition; Python's public connect() takes no arguments) reconnects an already connected
     * client. The new transports are swapped in only after every check passes, so a failed reconnect leaves the
     * client disconnected rather than half-connected.
     *
     * @throws WeaviateStartUpException
     * @throws AuthenticationException          when the server rejects the credentials
     * @throws InsufficientPermissionsException
     */
    public function connect(bool $force = false): void
    {
        if ($this->connected && !$force) {
            return;
        }
        $this->close();

        $rest = new RestTransport(
            $this->connectionParams->httpUrl(),
            $this->headers,
            $this->config->timeout,
            $this->config->httpClient,
            $this->config->proxies,
            $this->config->trustEnv,
        );

        // /v1/meta always runs, even with skipInitChecks: it gives the version and the gRPC message limit.
        try {
            $meta = $this->fetchMeta($rest);
        } catch (ConnectionException $e) {
            throw new WeaviateStartUpException('Could not connect to Weaviate: ' . $e->getMessage(), 0, $e);
        }
        $version = \is_string($meta['version'] ?? null) ? $meta['version'] : null;
        if ($version === null) {
            throw new WeaviateStartUpException(\sprintf(
                '%s/v1/meta did not return a Weaviate version. Is this a Weaviate server (and the REST port)?',
                $this->connectionParams->httpUrl(),
            ));
        }
        $serverVersion = ServerVersion::parse($version);
        if (!$serverVersion->isAtLeastVersion(Version::MIN_SERVER)) {
            throw new WeaviateStartUpException(\sprintf(
                'Weaviate version %s is not supported. Please use Weaviate version %s or higher.',
                $version,
                Version::MIN_SERVER,
            ));
        }

        $grpc = $this->createGrpcTransport();
        if (is_numeric($meta['grpcMaxMessageSize'] ?? null) && ($grpc instanceof CurlGrpcTransport || $grpc instanceof ExtGrpcTransport)) {
            $grpc->setMaxMessageLength((int) $meta['grpcMaxMessageSize']); // ignores values <= 0
        }
        if ($grpc instanceof CurlGrpcTransport && !$grpc->reusesConnections() && $this->connectionParams->grpc->secure) {
            $this->logger->warning(\sprintf(
                'libcurl %s cannot reuse HTTP/2 connections, so every gRPC call opens a new TLS connection '
                . '(about 4x slower against Weaviate Cloud). Use libcurl %s or later, or install ext-grpc.',
                self::libcurlVersion(),
                CurlGrpcTransport::MIN_LIBCURL_FOR_REUSE,
            ));
        }

        if (!$this->skipInitChecks) {
            try {
                $this->pingGrpc($grpc);
            } catch (\Throwable $e) {
                if ($grpc !== $this->config->grpcTransport) {
                    $grpc->close();
                }

                throw $e;
            }
        }

        $this->rest = $rest;
        $this->grpc = $grpc;
        $this->serverVersion = $serverVersion;
        $this->connected = true;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * `GET /v1/.well-known/ready`. Returns false on errors instead of throwing.
     */
    public function isReady(): bool
    {
        try {
            return $this->restTransport()->request('GET', '/.well-known/ready', timeoutClass: TimeoutClass::Init)->isSuccessful();
        } catch (WeaviateException) {
            return false;
        }
    }

    /**
     * `GET /v1/.well-known/live` and then the gRPC health check; true only when both pass. Returns false on
     * errors instead of throwing.
     */
    public function isLive(): bool
    {
        try {
            if (!$this->restTransport()->request('GET', '/.well-known/live', timeoutClass: TimeoutClass::Init)->isSuccessful()) {
                return false;
            }
            $this->pingGrpc($this->grpcTransport());

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
        return $this->fetchMeta($this->restTransport());
    }

    /**
     * @throws ClientClosedException when the client isn't connected
     */
    public function serverVersion(): ServerVersion
    {
        return $this->serverVersion ?? throw self::closedException();
    }

    public function close(): void
    {
        if ($this->grpc !== null && $this->grpc !== $this->config->grpcTransport) {
            $this->grpc->close(); // a transport the caller injected is theirs to close
        }
        $this->grpc = null;
        $this->rest = null;
        $this->serverVersion = null;
        $this->connected = false;
    }

    /**
     * @internal
     *
     * @throws ClientClosedException
     */
    public function restTransport(): RestTransport
    {
        return $this->rest ?? throw self::closedException();
    }

    /**
     * @internal
     *
     * @throws ClientClosedException
     */
    public function grpcTransport(): GrpcTransport
    {
        return $this->grpc ?? throw self::closedException();
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
        return $this->headers;
    }

    /**
     * @internal
     */
    public function timeouts(): Connect\Timeout
    {
        return $this->config->timeout;
    }

    private static function closedException(): ClientClosedException
    {
        return new ClientClosedException('The client is closed or not connected: call connect() first.');
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchMeta(RestTransport $rest): array
    {
        $response = $rest->requestExpecting('GET', '/meta', 'Get meta', timeoutClass: TimeoutClass::Init);
        /** @var array<string, mixed> $meta a JSON object decodes to string keys */
        $meta = \is_array($response->body) ? $response->body : [];

        return $meta;
    }

    private function pingGrpc(GrpcTransport $grpc): void
    {
        $timeout = (float) $this->config->timeout->init;
        if ($grpc instanceof CurlGrpcTransport && !$grpc->reusesConnections() && $this->connectionParams->grpc->secure) {
            $timeout = max($timeout, self::FRESH_TLS_HEALTH_TIMEOUT);
        }

        try {
            $response = $grpc->unary(self::HEALTH_METHOD, new WeaviateHealthCheckRequest(), WeaviateHealthCheckResponse::class, $timeout, $this->headers);
        } catch (AuthenticationException|InsufficientPermissionsException $e) {
            throw $e; // the endpoint is reachable; the credentials are the problem
        } catch (GrpcException|ConnectionException $e) {
            throw new WeaviateStartUpException(\sprintf(
                'The gRPC health check against %s failed. Check that the gRPC host and port are right and reachable. %s',
                $this->connectionParams->grpcUrl(),
                $e->getMessage(),
            ), 0, $e);
        }

        if ($response->getStatus() !== ServingStatus::SERVING) {
            throw new WeaviateStartUpException(\sprintf(
                'The gRPC health check against %s returned status %d (expected SERVING)',
                $this->connectionParams->grpcUrl(),
                $response->getStatus(),
            ));
        }
    }

    private static function libcurlVersion(): string
    {
        $version = curl_version();

        return \is_array($version) && \is_string($version['version'] ?? null) ? $version['version'] : 'unknown';
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
        $proxy = $this->config->proxies;
        $trustEnv = $this->config->trustEnv;
        $ext = static fn(): ExtGrpcTransport => new ExtGrpcTransport($endpoint, proxy: $proxy, userAgent: $userAgent, trustEnv: $trustEnv);
        $curl = fn(): CurlGrpcTransport => new CurlGrpcTransport(
            $endpoint,
            connectTimeout: (float) $this->config->timeout->init,
            proxy: $proxy,
            userAgent: $userAgent,
            trustEnv: $trustEnv,
        );

        return match ($choice) {
            GrpcTransportChoice::ExtGrpc => $ext(),
            GrpcTransportChoice::Curl => $curl(),
            GrpcTransportChoice::Auto => match (true) {
                ExtGrpcTransport::isSupported() => $ext(),
                CurlGrpcTransport::isSupported() => $curl(),
                default => throw new ConnectionException(
                    'No gRPC transport available: install ext-grpc, or use a libcurl built with HTTP/2 (nghttp2).',
                ),
            },
        };
    }

    /**
     * @param array<mixed> $userHeaders
     *
     * @return array<string, string>
     */
    private function buildHeaders(#[\SensitiveParameter] array $userHeaders): array
    {
        $headers = ['x-weaviate-client' => 'weaviate-client-php/' . Version::CLIENT . '-sync'];

        $host = $this->connectionParams->http->host;
        if (str_contains($host, 'weaviate.io') || str_contains($host, 'weaviate.cloud') || str_contains($host, 'semi.technology')) {
            $headers['x-weaviate-cluster-url'] = 'https://' . $host;
        }

        foreach (Headers::normalize($userHeaders) as $name => $value) {
            if ($name === 'x-weaviate-client') {
                continue;
            }
            $headers[$name] = $value;
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
