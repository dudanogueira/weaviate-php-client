<?php

declare(strict_types=1);

namespace Weaviate\Client\Tests\Unit\Connect;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Weaviate\Client\Connect\ConnectionParams;
use Weaviate\Client\Connect\ProtocolParams;
use Weaviate\Client\Exceptions\InvalidInputException;

final class ConnectionParamsTest extends TestCase
{
    public function testSeparateHostsPortsAndTlsPerProtocol(): void
    {
        $params = ConnectionParams::fromParams('api.example.com', 443, true, 'grpc.example.com', 50051, false);

        self::assertSame('https://api.example.com:443', $params->httpUrl());
        self::assertSame('http://grpc.example.com:50051', $params->grpcUrl());
    }

    public function testSameHostAndPortIsRejectedWithoutGrpcWeb(): void
    {
        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('http port and grpc port must be different');
        ConnectionParams::fromParams('localhost', 8080, false, 'localhost', 8080, false);
    }

    public function testSameHostAndPortIsAllowedWithGrpcWeb(): void
    {
        $params = ConnectionParams::fromParams('vdb.example.com', 443, true, 'vdb.example.com', 443, true, 'v1/grpc-web/');

        self::assertSame('/v1/grpc-web', $params->grpcWebPathPrefix());
    }

    public function testFromUrlDefaultsPortsAndInheritsTlsFromHttps(): void
    {
        $https = ConnectionParams::fromUrl('https://vdb.example.com', 50051);
        self::assertSame(443, $https->http->port);
        self::assertTrue($https->grpc->secure);

        $http = ConnectionParams::fromUrl('http://localhost:8081', 50052);
        self::assertSame(8081, $http->http->port);
        self::assertFalse($http->grpc->secure);
        self::assertSame(50052, $http->grpc->port);
    }

    public function testFromUrlRejectsOtherSchemes(): void
    {
        $this->expectException(InvalidInputException::class);
        ConnectionParams::fromUrl('grpc://localhost', 50051);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidHosts(): iterable
    {
        yield 'path' => ['a/b'];
        yield 'space' => ['a b'];
        yield 'scheme' => ['http://localhost'];
        yield 'port' => ['localhost:8080'];
        yield 'userinfo' => ['user@localhost'];
        yield 'bad ipv6' => ['::zz'];
    }

    #[DataProvider('invalidHosts')]
    public function testInvalidHostsAreRejected(string $host): void
    {
        $this->expectException(InvalidInputException::class);
        new ProtocolParams($host, 8080, false);
    }

    public function testIpv6HostsAreBracketedInUrls(): void
    {
        self::assertSame('http://[::1]:8080', (new ProtocolParams('::1', 8080, false))->url());
        self::assertSame('https://[2001:db8::1]:443', (new ProtocolParams('[2001:db8::1]', 443, true))->url());
        self::assertSame('http://[::1]:8080', ConnectionParams::fromUrl('http://[::1]:8080', 50051)->httpUrl());
    }

    public function testHostComparisonIsCaseInsensitive(): void
    {
        $this->expectException(InvalidInputException::class);
        ConnectionParams::fromParams('LocalHost', 8080, false, 'localhost', 8080, false);
    }

    public function testFromUrlAcceptsAnUppercaseScheme(): void
    {
        self::assertTrue(ConnectionParams::fromUrl('HTTPS://VDB.example.com', 50051)->http->secure);
        self::assertSame('vdb.example.com', ConnectionParams::fromUrl('HTTPS://VDB.example.com', 50051)->http->host);
    }

    public function testProtocolParamsValidation(): void
    {
        try {
            new ProtocolParams('', 80, false);
            self::fail('empty host accepted');
        } catch (InvalidInputException) {
        }

        $this->expectException(InvalidInputException::class);
        new ProtocolParams('localhost', 70000, false);
    }
}
