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
 * - Always HTTP/2 with prior knowledge: h2c on plaintext. Over TLS, ALPN must offer **only** "h2", like grpcio
 *   does. Weaviate Cloud's Envoy ingress picks http/1.1 when a client offers "h2,http/1.1", and gRPC can't run
 *   on HTTP/1.1 (no trailers). libcurl 8.12+ offers only "h2" in prior-knowledge mode (verified; 8.9.1 and
 *   older still offer both), so on older libcurl ALPN is disabled over TLS and the h2 preface is sent directly.
 * - The status arrives in HTTP/2 trailers, which libcurl passes to the header callback.
 * - One curl handle is kept per transport, so the HTTP/2 connection is reused across calls.
 * - libcurl before 8.4.0 can't reuse an HTTP/2 connection for a second POST (7.88.1, Debian bookworm, fails
 *   with "Error in the HTTP2 framing layer"; 8.4.0+ verified working). On those versions every call opens a
 *   fresh connection instead: correct, but slower.
 *
 * - Errors match ExtGrpcTransport: network failures are UNAVAILABLE, size limits RESOURCE_EXHAUSTED, deadlines
 *   DEADLINE_EXCEEDED, and UNAUTHENTICATED / PERMISSION_DENIED map to the auth exceptions (GrpcErrors).
 * - Proxies: `$proxy` if given; otherwise HTTP(S)_PROXY from the environment only when `$trustEnv` is true.
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

    /** First libcurl version verified to offer only "h2" in ALPN with HTTP/2 prior knowledge over TLS. */
    public const MIN_LIBCURL_FOR_H2_ONLY_ALPN = '8.12.0';

    private ?\CurlHandle $handle = null;

    private readonly bool $reuseConnections;

    private readonly bool $h2OnlyAlpn;

    public function __construct(
        private readonly ProtocolParams $endpoint,
        private int $maxMessageLength = self::DEFAULT_MAX_MESSAGE_LENGTH,
        private readonly float $connectTimeout = 2.0,
        #[\SensitiveParameter]
        private readonly ?string $proxy = null,
        private readonly ?string $userAgent = null,
        ?bool $reuseConnections = null,
        private readonly bool $trustEnv = false,
    ) {
        if (!self::isSupported()) {
            throw new ConnectionException(
                'CurlGrpcTransport needs ext-curl built with HTTP/2 (nghttp2). '
                . 'Install a libcurl with HTTP/2 support, or install ext-grpc.',
            );
        }
        // null = decide from the libcurl version; false forces a fresh connection per call.
        $this->reuseConnections = $reuseConnections ?? self::canReuseConnections();
        $this->h2OnlyAlpn = self::libcurlAtLeast(self::MIN_LIBCURL_FOR_H2_ONLY_ALPN);
    }

    /**
     * Whether this libcurl reuses HTTP/2 connections correctly (>= 8.4.0).
     */
    public static function canReuseConnections(): bool
    {
        return self::libcurlAtLeast(self::MIN_LIBCURL_FOR_REUSE);
    }

    private static function libcurlAtLeast(string $minimum): bool
    {
        $version = curl_version();

        return \is_array($version) && \is_string($version['version'] ?? null)
            && version_compare($version['version'], $minimum, '>=');
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
        if ($maxMessageLength > 0) {
            $this->maxMessageLength = $maxMessageLength;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['endpoint' => $this->endpoint->url(), 'reuseConnections' => $this->reuseConnections, 'proxy' => $this->proxy === null ? null : '***'];
    }

    public function unary(string $method, Message $request, string $responseClass, float $timeout, array $metadata = []): Message
    {
        $payload = $request->serializeToString();
        if (\strlen($payload) > $this->maxMessageLength) {
            throw new GrpcException($method, GrpcStatus::ResourceExhausted, \sprintf(
                'request of %d bytes exceeds the maximum of %d bytes',
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
            $key = strtolower($key);
            // Binary metadata travels base64-encoded (gRPC spec); ext-grpc does this itself.
            $headers[] = $key . ': ' . (str_ends_with($key, '-bin') ? base64_encode($value) : $value);
        }

        /** @var array<string, list<string>> $received */
        $received = [];
        $body = '';
        $limit = $this->maxMessageLength + 5; // one frame: 5-byte prefix + message
        $tooLarge = false;
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
            \CURLOPT_HTTP_VERSION => \CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE,
            // Stream the body so an oversized response is aborted instead of buffered in full.
            \CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$tooLarge, $limit): int {
                if (\strlen($body) + \strlen($chunk) > $limit) {
                    $tooLarge = true;

                    return 0;
                }
                $body .= $chunk;

                return \strlen($chunk);
            },
            \CURLOPT_TIMEOUT_MS => max(1, (int) ceil($timeout * 1000)),
            // Without reuse every call pays a (TLS) handshake, which can stall for seconds over the internet
            // (measured p95 2.5 s against Weaviate Cloud), so the call's own deadline bounds the connect too.
            \CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) ceil(($this->reuseConnections ? min($this->connectTimeout, $timeout) : $timeout) * 1000)),
            \CURLOPT_TCP_KEEPALIVE => 1,
            \CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$received): int {
                $colon = strpos($line, ':');
                if ($colon !== false) {
                    $received[strtolower(trim(substr($line, 0, $colon)))][] = trim(substr($line, $colon + 1));
                }

                return \strlen($line);
            },
        ];
        if ($this->endpoint->secure && !$this->h2OnlyAlpn) {
            $options[\CURLOPT_SSL_ENABLE_ALPN] = false;
        }
        if (!$this->reuseConnections) {
            $options[\CURLOPT_FORBID_REUSE] = true;
            $options[\CURLOPT_FRESH_CONNECT] = true;
        }
        if ($this->proxy !== null) {
            $options[\CURLOPT_PROXY] = $this->proxy;
            $options[\CURLOPT_HTTPPROXYTUNNEL] = true;
        } elseif (!$this->trustEnv) {
            // An empty proxy disables libcurl's own HTTP(S)_PROXY / ALL_PROXY lookup.
            $options[\CURLOPT_PROXY] = '';
        }
        // PHPStan's curl_setopt_array shape doesn't know CURLOPT_HEADERFUNCTION closures or the proxy options.
        curl_setopt_array($handle, $options); // @phpstan-ignore argument.type

        $ok = curl_exec($handle);
        $errno = curl_errno($handle);

        if ($tooLarge) {
            throw new GrpcException($method, GrpcStatus::ResourceExhausted, \sprintf('response exceeds the maximum of %d bytes', $this->maxMessageLength));
        }
        if ($errno === \CURLE_OPERATION_TIMEDOUT) {
            // A timeout before the connection was established is a reachability problem, not a slow call.
            $connected = (float) curl_getinfo($handle, \CURLINFO_CONNECT_TIME) > 0.0;

            throw $connected
                ? new GrpcException($method, GrpcStatus::DeadlineExceeded, curl_error($handle))
                : new GrpcException($method, GrpcStatus::Unavailable, \sprintf('connecting to %s timed out', $this->endpoint->url()));
        }
        if ($ok === false || $errno !== 0) {
            $hint = \in_array($errno, [16 /* HTTP2 */, 52 /* GOT_NOTHING */, 56 /* RECV_ERROR */], true)
                ? ' (is this the gRPC port? It must speak HTTP/2)'
                : '';

            throw new GrpcException($method, GrpcStatus::Unavailable, \sprintf('%s: %s%s', $this->endpoint->url(), curl_error($handle), $hint));
        }

        $httpVersion = curl_getinfo($handle, \CURLINFO_HTTP_VERSION);
        if ($httpVersion !== \CURL_HTTP_VERSION_2_0) {
            throw new GrpcException($method, GrpcStatus::Unavailable, \sprintf(
                'the server at %s did not speak HTTP/2 (is the gRPC port right?)',
                $this->endpoint->url(),
            ));
        }

        $httpStatus = (int) curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
        $grpcStatus = $received['grpc-status'][0] ?? null;
        if ($httpStatus !== 200 && $grpcStatus === null) {
            throw GrpcErrors::fromStatus($method, GrpcStatus::fromHttpStatus($httpStatus), \sprintf('HTTP status %d', $httpStatus));
        }

        // grpc-status is in the trailers, or in the headers of a trailers-only response.
        if ($grpcStatus === null) {
            throw new GrpcException($method, GrpcStatus::Internal, 'response has no grpc-status');
        }
        $status = GrpcStatus::fromCode((int) $grpcStatus);
        if ($status !== GrpcStatus::Ok) {
            throw GrpcErrors::fromStatus($method, $status, rawurldecode(implode(', ', $received['grpc-message'] ?? [])));
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

    public function reusesConnections(): bool
    {
        return $this->reuseConnections;
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
        return $this->endpoint->url();
    }
}
