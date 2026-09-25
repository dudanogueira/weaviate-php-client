<?php

declare(strict_types=1);

namespace Weaviate\Client\Transport\Grpc;

use Weaviate\Client\Exceptions\AuthenticationException;
use Weaviate\Client\Exceptions\GrpcException;
use Weaviate\Client\Exceptions\InsufficientPermissionsException;
use Weaviate\Client\Exceptions\WeaviateException;

/**
 * Maps a non-OK gRPC status to the client's exception types, identically for every transport.
 *
 * @internal
 */
final class GrpcErrors
{
    private function __construct() {}

    public static function fromStatus(string $method, GrpcStatus $status, string $message): WeaviateException
    {
        $grpc = new GrpcException($method, $status, $message);

        return match (true) {
            $status === GrpcStatus::Unauthenticated,
            self::isUnknownAuthFailure($status, $message) => new AuthenticationException($grpc->getMessage(), $status->value, $grpc),
            $status === GrpcStatus::PermissionDenied => new InsufficientPermissionsException($method, 403, $message, $grpc),
            default => $grpc,
        };
    }

    /**
     * Weaviate (verified on 1.39.7) reports a missing or invalid API key over gRPC as UNKNOWN with the message
     * "extract auth: unauthorized: invalid api key" instead of UNAUTHENTICATED. RBAC denials correctly use
     * PERMISSION_DENIED. Only that auth-extraction message is matched, to avoid misclassifying other errors.
     */
    private static function isUnknownAuthFailure(GrpcStatus $status, string $message): bool
    {
        return $status === GrpcStatus::Unknown && str_contains(strtolower($message), 'extract auth: unauthorized');
    }
}
