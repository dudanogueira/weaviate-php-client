<?php

declare(strict_types=1);

namespace Weaviate\Client\Transport\Grpc;

use Google\Protobuf\Internal\Message;
use Weaviate\Client\Connect\ProtocolParams;
use Weaviate\Client\Exceptions\ConnectionException;
use Weaviate\Client\Exceptions\GrpcException;

/**
 * gRPC through the ext-grpc PECL extension. Chosen automatically when the extension is loaded.
 *
 * Unary calls only for now. Bidirectional streaming (BatchStream) is planned (docs/14-batch.md §9).
 *
 * @internal
 */
final class ExtGrpcTransport implements GrpcTransport
{
    public const DEFAULT_MAX_MESSAGE_LENGTH = CurlGrpcTransport::DEFAULT_MAX_MESSAGE_LENGTH;

    private ?\Grpc\Channel $channel = null;

    public function __construct(
        private readonly ProtocolParams $endpoint,
        private int $maxMessageLength = self::DEFAULT_MAX_MESSAGE_LENGTH,
    ) {
        if (!self::isSupported()) {
            throw new ConnectionException('ExtGrpcTransport needs the grpc PECL extension');
        }
    }

    public static function isSupported(): bool
    {
        return \extension_loaded('grpc');
    }

    public function setMaxMessageLength(int $maxMessageLength): void
    {
        $this->maxMessageLength = $maxMessageLength;
        $this->channel = null;
    }

    public function unary(string $method, Message $request, string $responseClass, float $timeout, array $metadata = []): Message
    {
        $grpcMetadata = [];
        foreach ($metadata as $key => $value) {
            $grpcMetadata[strtolower($key)] = [$value];
        }

        // The low-level API of the extension itself, so the grpc/grpc Composer package isn't needed.
        $deadline = \Grpc\Timeval::now()->add(new \Grpc\Timeval(max(1, (int) ceil($timeout * 1_000_000))));
        $call = new \Grpc\Call($this->channel(), $method, $deadline);
        /** @var object{status: object{code: int, details: string}, message: ?string} $event */
        $event = $call->startBatch([
            \Grpc\OP_SEND_INITIAL_METADATA => $grpcMetadata,
            \Grpc\OP_SEND_MESSAGE => ['message' => $request->serializeToString()],
            \Grpc\OP_SEND_CLOSE_FROM_CLIENT => true,
            \Grpc\OP_RECV_INITIAL_METADATA => true,
            \Grpc\OP_RECV_MESSAGE => true,
            \Grpc\OP_RECV_STATUS_ON_CLIENT => true,
        ]);

        $code = GrpcStatus::fromCode($event->status->code);
        if ($code !== GrpcStatus::Ok) {
            throw new GrpcException($method, $code, $event->status->details);
        }
        if (!\is_string($event->message)) {
            throw new GrpcException($method, GrpcStatus::Internal, 'empty response');
        }

        $response = new $responseClass();
        try {
            $response->mergeFromString($event->message);
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
        return 'ext-grpc';
    }

    public function close(): void
    {
        $this->channel?->close();
        $this->channel = null;
    }

    private function channel(): \Grpc\Channel
    {
        return $this->channel ??= new \Grpc\Channel(\sprintf('%s:%d', $this->endpoint->host, $this->endpoint->port), [
            'credentials' => $this->endpoint->secure
                ? \Grpc\ChannelCredentials::createSsl()
                : \Grpc\ChannelCredentials::createInsecure(),
            'grpc.max_send_message_length' => $this->maxMessageLength,
            'grpc.max_receive_message_length' => $this->maxMessageLength,
            'grpc.default_authority' => $this->endpoint->host,
        ]);
    }
}
