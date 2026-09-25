<?php

declare(strict_types=1);

namespace Weaviate\Client\Connect;

/**
 * Which gRPC transport to use. `Auto` picks ext-grpc when loaded, otherwise curl HTTP/2 (docs/01-architecture.md).
 */
enum GrpcTransportChoice
{
    case Auto;
    case Curl;
    case ExtGrpc;
}
