<?php

declare(strict_types=1);

namespace Weaviate\Client\Tests\Unit\Transport\Grpc;

use PHPUnit\Framework\TestCase;
use Weaviate\Client\Transport\Grpc\GrpcStatus;

final class GrpcStatusTest extends TestCase
{
    public function testUnknownCodesMapToUnknown(): void
    {
        self::assertSame(GrpcStatus::NotFound, GrpcStatus::fromCode(5));
        self::assertSame(GrpcStatus::Unknown, GrpcStatus::fromCode(99));
    }

    public function testHttpStatusMappingFollowsTheGrpcSpec(): void
    {
        self::assertSame(GrpcStatus::Internal, GrpcStatus::fromHttpStatus(400));
        self::assertSame(GrpcStatus::Unauthenticated, GrpcStatus::fromHttpStatus(401));
        self::assertSame(GrpcStatus::PermissionDenied, GrpcStatus::fromHttpStatus(403));
        self::assertSame(GrpcStatus::Unimplemented, GrpcStatus::fromHttpStatus(404));
        self::assertSame(GrpcStatus::Unavailable, GrpcStatus::fromHttpStatus(503));
        self::assertSame(GrpcStatus::Unknown, GrpcStatus::fromHttpStatus(418));
    }
}
