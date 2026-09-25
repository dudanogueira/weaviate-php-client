<?php

declare(strict_types=1);

namespace Weaviate\Client\Transport\Grpc;

use Google\Protobuf\Internal\Message;
use Weaviate\Client\Exceptions\ConnectionException;
use Weaviate\Client\Exceptions\GrpcException;

/**
 * A gRPC channel to Weaviate. See docs/01-architecture.md "Transports" and ADR 0002.
 *
 * @internal
 */
interface GrpcTransport
{
    /**
     * Makes one unary call.
     *
     * @template T of Message
     *
     * @param string                $method        full method path, e.g. "/weaviate.v1.Weaviate/Search"
     * @param class-string<T>       $responseClass
     * @param float                 $timeout       deadline in seconds
     * @param array<string, string> $metadata      lowercase keys
     *
     * @return T
     *
     * @throws GrpcException       on a non-OK gRPC status (including DEADLINE_EXCEEDED)
     * @throws ConnectionException on network, TLS or protocol failures
     */
    public function unary(string $method, Message $request, string $responseClass, float $timeout, array $metadata = []): Message;

    /**
     * Whether bidirectional streaming (BatchStream) is available on this transport.
     */
    public function supportsBidiStreaming(): bool;

    /**
     * A short name for logs and diagnostics, e.g. "curl" or "ext-grpc".
     */
    public function name(): string;

    public function close(): void;
}
