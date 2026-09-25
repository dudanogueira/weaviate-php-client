<?php

declare(strict_types=1);

namespace Weaviate\Client\Transport\Grpc;

/**
 * gRPC status codes (https://grpc.github.io/grpc/core/md_doc_statuscodes.html).
 */
enum GrpcStatus: int
{
    case Ok = 0;
    case Cancelled = 1;
    case Unknown = 2;
    case InvalidArgument = 3;
    case DeadlineExceeded = 4;
    case NotFound = 5;
    case AlreadyExists = 6;
    case PermissionDenied = 7;
    case ResourceExhausted = 8;
    case FailedPrecondition = 9;
    case Aborted = 10;
    case OutOfRange = 11;
    case Unimplemented = 12;
    case Internal = 13;
    case Unavailable = 14;
    case DataLoss = 15;
    case Unauthenticated = 16;

    public static function fromCode(int $code): self
    {
        return self::tryFrom($code) ?? self::Unknown;
    }

    /**
     * Maps a non-200 HTTP status on a gRPC call, per the gRPC HTTP/2 spec ("HTTP to gRPC status code mapping").
     */
    public static function fromHttpStatus(int $httpStatus): self
    {
        return match ($httpStatus) {
            400 => self::Internal,
            401 => self::Unauthenticated,
            403 => self::PermissionDenied,
            404 => self::Unimplemented,
            429, 502, 503, 504 => self::Unavailable,
            default => self::Unknown,
        };
    }
}
