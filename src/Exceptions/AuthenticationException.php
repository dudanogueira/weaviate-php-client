<?php

declare(strict_types=1);

namespace Weaviate\Client\Exceptions;

/**
 * The server rejected the credentials (HTTP 401 / gRPC UNAUTHENTICATED).
 */
class AuthenticationException extends WeaviateException {}
