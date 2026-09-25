<?php

declare(strict_types=1);

namespace Weaviate\Client\Tests\Unit\Transport\Grpc;

use PHPUnit\Framework\TestCase;
use Weaviate\Client\Exceptions\AuthenticationException;
use Weaviate\Client\Exceptions\GrpcException;
use Weaviate\Client\Exceptions\InsufficientPermissionsException;
use Weaviate\Client\Exceptions\UnexpectedStatusCodeException;
use Weaviate\Client\Transport\Grpc\GrpcErrors;
use Weaviate\Client\Transport\Grpc\GrpcStatus;

final class GrpcErrorsTest extends TestCase
{
    public function testUnauthenticatedMapsToAuthenticationException(): void
    {
        $e = GrpcErrors::fromStatus('/m', GrpcStatus::Unauthenticated, 'no token');

        self::assertInstanceOf(AuthenticationException::class, $e);
        self::assertInstanceOf(GrpcException::class, $e->getPrevious());
    }

    public function testWeaviatesUnknownAuthFailureMapsToAuthenticationException(): void
    {
        self::assertInstanceOf(AuthenticationException::class, GrpcErrors::fromStatus('/m', GrpcStatus::Unknown, 'extract auth: unauthorized: invalid api key'));
        self::assertInstanceOf(GrpcException::class, GrpcErrors::fromStatus('/m', GrpcStatus::Unknown, 'some other unauthorized-looking failure'));
    }

    public function testPermissionDeniedIsA403LikePython(): void
    {
        $e = GrpcErrors::fromStatus('/weaviate.v1.Weaviate/Search', GrpcStatus::PermissionDenied, 'forbidden');

        self::assertInstanceOf(InsufficientPermissionsException::class, $e);
        self::assertInstanceOf(UnexpectedStatusCodeException::class, $e, 'catch (UnexpectedStatusCodeException) also catches it');
        self::assertSame(403, $e->statusCode);
    }

    public function testOtherStatusesStayGrpcExceptions(): void
    {
        $e = GrpcErrors::fromStatus('/m', GrpcStatus::NotFound, 'no such collection');

        self::assertInstanceOf(GrpcException::class, $e);
        self::assertSame(GrpcStatus::NotFound, $e->grpcStatus);
    }
}
