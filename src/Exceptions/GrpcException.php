<?php

declare(strict_types=1);

namespace Weaviate\Client\Exceptions;

use Weaviate\Client\Transport\Grpc\GrpcStatus;

/**
 * A gRPC call finished with a non-OK status.
 */
class GrpcException extends WeaviateException
{
    public function __construct(
        public readonly string $method,
        public readonly GrpcStatus $grpcStatus,
        public readonly string $grpcMessage,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            \sprintf('gRPC %s failed with %s: %s', $method, $grpcStatus->name, $grpcMessage),
            $grpcStatus->value,
            $previous,
        );
    }
}
