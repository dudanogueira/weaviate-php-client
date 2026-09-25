<?php

declare(strict_types=1);

namespace Weaviate\Client\Tests\Support;

use Google\Protobuf\Internal\Message;
use Weaviate\Client\Proto\V1\WeaviateHealthCheckResponse;
use Weaviate\Client\Proto\V1\WeaviateHealthCheckResponse\ServingStatus;
use Weaviate\Client\Transport\Grpc\GrpcTransport;

/**
 * GrpcTransport double: answers by method (a Message, a Throwable, or a callable) and records calls.
 */
final class FakeGrpcTransport implements GrpcTransport
{
    /** @var list<array{method: string, request: Message, timeout: float, metadata: array<string, string>}> */
    public array $calls = [];

    public int $closeCount = 0;

    /**
     * @param array<string, Message|\Throwable|callable(Message): Message> $responses
     */
    public function __construct(private array $responses = []) {}

    public static function serving(): self
    {
        return new self(['/grpc.health.v1.Health/Check' => new WeaviateHealthCheckResponse(['status' => ServingStatus::SERVING])]);
    }

    public function respond(string $method, Message|\Throwable|callable $response): self
    {
        $this->responses[$method] = $response;

        return $this;
    }

    public function unary(string $method, Message $request, string $responseClass, float $timeout, #[\SensitiveParameter] array $metadata = []): Message
    {
        $this->calls[] = ['method' => $method, 'request' => $request, 'timeout' => $timeout, 'metadata' => $metadata];
        $response = $this->responses[$method] ?? throw new \LogicException('no fake response for ' . $method);
        if ($response instanceof \Throwable) {
            throw $response;
        }
        if (\is_callable($response)) {
            $response = $response($request);
        }
        \assert($response instanceof $responseClass);

        return $response;
    }

    public function supportsBidiStreaming(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'fake';
    }

    public function close(): void
    {
        ++$this->closeCount;
    }
}
