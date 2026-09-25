<?php

declare(strict_types=1);

namespace Weaviate\Client\Transport\Grpc;

use Google\Protobuf\Internal\Message;
use Weaviate\Client\Connect\ProtocolParams;
use Weaviate\Client\Exceptions\ConnectionException;
use Weaviate\Client\Exceptions\GrpcException;
use Weaviate\Client\Exceptions\InvalidInputException;

/**
 * gRPC through the ext-grpc PECL extension. Chosen automatically when the extension is loaded.
 *
 * Uses the extension's low-level Call API, so the grpc/grpc Composer package isn't needed. Unary calls only
 * for now; bidirectional streaming (BatchStream) is planned (docs/14-batch.md §9). Errors are mapped exactly
 * like CurlGrpcTransport (GrpcErrors).
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
        #[\SensitiveParameter]
        private readonly ?string $proxy = null,
        private readonly ?string $userAgent = null,
        private readonly bool $trustEnv = false,
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
        if ($maxMessageLength > 0 && $maxMessageLength !== $this->maxMessageLength) {
            $this->maxMessageLength = $maxMessageLength;
            $this->close();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['endpoint' => $this->endpoint->url(), 'proxy' => $this->proxy === null ? null : '***'];
    }

    public function unary(string $method, Message $request, string $responseClass, float $timeout, #[\SensitiveParameter] array $metadata = []): Message
    {
        $payload = $request->serializeToString();
        if (\strlen($payload) > $this->maxMessageLength) {
            throw new GrpcException($method, GrpcStatus::ResourceExhausted, \sprintf(
                'request of %d bytes exceeds the maximum of %d bytes',
                \strlen($payload),
                $this->maxMessageLength,
            ));
        }

        $grpcMetadata = [];
        foreach ($metadata as $key => $value) {
            $grpcMetadata[strtolower($key)] = [$value];
        }

        $deadline = \Grpc\Timeval::now()->add(new \Grpc\Timeval(max(1, (int) ceil($timeout * 1_000_000))));
        $call = new \Grpc\Call($this->channel(), $method, $deadline);
        try {
            /** @var object{status: object{code: int, details: string}, message: ?string} $event */
            $event = $call->startBatch([
                \Grpc\OP_SEND_INITIAL_METADATA => $grpcMetadata,
                \Grpc\OP_SEND_MESSAGE => ['message' => $payload],
                \Grpc\OP_SEND_CLOSE_FROM_CLIENT => true,
                \Grpc\OP_RECV_INITIAL_METADATA => true,
                \Grpc\OP_RECV_MESSAGE => true,
                \Grpc\OP_RECV_STATUS_ON_CLIENT => true,
            ]);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidInputException('Invalid gRPC metadata: ' . $e->getMessage(), 0, $e);
        }

        $code = GrpcStatus::fromCode($event->status->code);
        if ($code !== GrpcStatus::Ok) {
            throw GrpcErrors::fromStatus($method, $code, $event->status->details);
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
        if ($this->channel !== null) {
            return $this->channel;
        }

        $options = [
            'credentials' => $this->endpoint->secure
                ? \Grpc\ChannelCredentials::createSsl()
                : \Grpc\ChannelCredentials::createInsecure(),
            'grpc.max_send_message_length' => $this->maxMessageLength,
            'grpc.max_receive_message_length' => $this->maxMessageLength,
            'grpc.default_authority' => $this->endpoint->host,
        ];
        if ($this->userAgent !== null) {
            $options['grpc.primary_user_agent'] = $this->userAgent;
        }
        if ($this->proxy !== null) {
            $options['grpc.http_proxy'] = $this->proxy;
        } elseif (!$this->trustEnv) {
            // grpc-core reads grpc_proxy / https_proxy / http_proxy unless told not to.
            $options['grpc.enable_http_proxy'] = 0;
        }

        return $this->channel = new \Grpc\Channel($this->endpoint->authority(), $options);
    }
}
