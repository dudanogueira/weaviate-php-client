<?php

declare(strict_types=1);

namespace Weaviate\Client\Transport\Grpc;

use Google\Protobuf\Internal\Message;
use Weaviate\Client\Connect\ProtocolParams;
use Weaviate\Client\Exceptions\ConnectionException;
use Weaviate\Client\Exceptions\GrpcException;

/**
 * Pure-PHP gRPC over HTTP/2 using ext-curl (built with nghttp2). Unary calls only.
 *
 * - Plaintext endpoints use h2c with prior knowledge; TLS endpoints negotiate h2 through ALPN.
 * - The status arrives in HTTP/2 trailers, which libcurl passes to the header callback.
 * - One curl handle is kept per transport, so the HTTP/2 connection is reused across calls.
 * - libcurl before 8.4.0 can't reuse an HTTP/2 connection for a second POST (7.88.1, Debian bookworm, fails
 *   with "Error in the HTTP2 framing layer"; 8.4.0+ verified working). On those versions every call opens a
 *   fresh connection instead: correct, but slower.
 *
 * See ADR 0002.
 *
 * @internal
 */
final class CurlGrpcTransport implements GrpcTransport
{
    /** Python's default; overridden by `grpcMaxMessageSize` from /v1/meta. */
    public const DEFAULT_MAX_MESSAGE_LENGTH = 104_858_000;

    /** First libcurl version verified to reuse HTTP/2 connections correctly (see the class docblock). */
    public const MIN_LIBCURL_FOR_REUSE = '8.4.0';

    private ?\CurlHandle $handle = null;

    private readonly bool $reuseConnections;

    public function __construct(
        private readonly ProtocolParams $endpoint,
        private int $maxMessageLength = self::DEFAULT_MAX_MESSAGE_LENGTH,
        private readonly float $connectTimeout = 2.0,
        private readonly ?string $proxy = null,
        private readonly ?string $userAgent = null,
    ) {
        if (!self::isSupported()) {
            throw new ConnectionException(
                'CurlGrpcTransport needs ext-curl built with HTTP/2 (nghttp2). '
                . 'Install a libcurl with HTTP/2 support, or install ext-grpc.',
            );
        }
        $this->reuseConnections = self::canReuseConnections();
    }

    /**
     * Whether this libcurl reuses HTTP/2 connections correctly (>= 8.4.0).
     */
    public static function canReuseConnections(): bool
    {
        $version = curl_version();

        return \is_array($version) && \is_string($version['version'] ?? null)
            && version_compare($version['version'], self::MIN_LIBCURL_FOR_REUSE, '>=');
    }

    public static function isSupported(): bool
    {
        if (!\function_exists('curl_version')) {
            return false;
        }
        $version = curl_version();

        return \is_array($version) && \is_int($version['features'] ?? null) && ($version['features'] & \CURL_VERSION_HTTP2) !== 0;
    }

    public function setMaxMessageLength(int $maxMessageLength): void
    {
        $this->maxMessageLength = $maxMessageLength;
    }

    public function unary(string $method, Message $request, string $responseClass, float $timeout, array $metadata = []): Message
    {
        $payload = $request->serializeToString();
        if (\strlen($payload) > $this->maxMessageLength) {
            throw new ConnectionException(\sprintf(
                'gRPC request of %d bytes exceeds the maximum of %d bytes',
                \strlen($payload),
                $this->maxMessageLength,
            ));
        }

        $headers = [
            'content-type: application/grpc+proto',
            'te: trailers',
            'grpc-timeout: ' . Framing::timeoutHeader($timeout),
            'user-agent: ' . ($this->userAgent ?? 'weaviate-php-client-curl'),
            'expect:',
        ];
        foreach ($metadata as $key => $value) {
            $headers[] = strtolower($key) . ': ' . $value;
        }

        /** @var array<string, string> $received */
        $received = [];
        if (!$this->reuseConnections) {
            $this->handle = null;
        }
        $handle = $this->handle();
        curl_reset($handle);
        $options = [
            \CURLOPT_URL => $this->baseUrl() . $method,
            \CURLOPT_POST => true,
            \CURLOPT_POSTFIELDS => Framing::encode($payload),
            \CURLOPT_HTTPHEADER => $headers,
            \CURLOPT_HTTP_VERSION => $this->endpoint->secure ? \CURL_HTTP_VERSION_2TLS : \CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT_MS => max(1, (int) ceil($timeout * 1000)),
            \CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) ceil($this->connectTimeout * 1000)),
            \CURLOPT_TCP_KEEPALIVE => 1,
            \CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$received): int {
                $colon = strpos($line, ':');
                if ($colon !== false) {
                    $received[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
                }

                return \strlen($line);
            },
        ];
        if (!$this->reuseConnections) {
            $options[\CURLOPT_FORBID_REUSE] = true;
            $options[\CURLOPT_FRESH_CONNECT] = true;
        }
        if ($this->proxy !== null) {
            $options[\CURLOPT_PROXY] = $this->proxy;
            $options[\CURLOPT_HTTPPROXYTUNNEL] = true;
        }
        // PHPStan's curl_setopt_array shape doesn't know CURLOPT_HEADERFUNCTION closures or the proxy options.
        curl_setopt_array($handle, $options); // @phpstan-ignore argument.type

        $body = curl_exec($handle);
        $errno = curl_errno($handle);

        if ($errno === \CURLE_OPERATION_TIMEDOUT) {
            throw new GrpcException($method, GrpcStatus::DeadlineExceeded, curl_error($handle));
        }
        if ($body === false || $errno !== 0) {
            throw new ConnectionException(\sprintf('gRPC %s to %s failed: %s', $method, $this->baseUrl(), curl_error($handle)));
        }
        \assert(\is_string($body));

        $httpVersion = curl_getinfo($handle, \CURLINFO_HTTP_VERSION);
        if ($httpVersion !== \CURL_HTTP_VERSION_2_0) {
            throw new ConnectionException(\sprintf(
                'gRPC %s: the server at %s did not speak HTTP/2 (is the gRPC port right?)',
                $method,
                $this->baseUrl(),
            ));
        }

        $httpStatus = (int) curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
        if ($httpStatus !== 200) {
            throw new GrpcException($method, GrpcStatus::fromHttpStatus($httpStatus), \sprintf('HTTP status %d', $httpStatus));
        }

        // grpc-status is in the trailers, or in the headers of a trailers-only response.
        if (!isset($received['grpc-status'])) {
            throw new GrpcException($method, GrpcStatus::Internal, 'response has no grpc-status');
        }
        $status = GrpcStatus::fromCode((int) $received['grpc-status']);
        if ($status !== GrpcStatus::Ok) {
            throw new GrpcException($method, $status, rawurldecode($received['grpc-message'] ?? ''));
        }

        $messages = Framing::decode($body, $this->maxMessageLength);
        if (\count($messages) !== 1) {
            throw new GrpcException($method, GrpcStatus::Internal, \sprintf('expected 1 response message, got %d', \count($messages)));
        }

        $response = new $responseClass();
        try {
            $response->mergeFromString($messages[0]);
        } catch (\Exception $e) {
            throw new GrpcException($method, GrpcStatus::Internal, 'could not decode response: ' . $e->getMessage(), $e);
        }

        return $response;
    }

    public function supportsBidiStreaming(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'curl';
    }

    public function close(): void
    {
        $this->handle = null;
    }

    private function handle(): \CurlHandle
    {
        return $this->handle ??= curl_init();
    }

    private function baseUrl(): string
    {
        return \sprintf('%s://%s:%d', $this->endpoint->secure ? 'https' : 'http', $this->endpoint->host, $this->endpoint->port);
    }
}
