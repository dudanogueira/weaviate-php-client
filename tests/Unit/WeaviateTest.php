<?php

declare(strict_types=1);

namespace Weaviate\Client\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Weaviate\Client\Connect\Auth;
use Weaviate\Client\Connect\ConnectionParams;
use Weaviate\Client\Exceptions\InvalidInputException;
use Weaviate\Client\ServerVersion;
use Weaviate\Client\Version;
use Weaviate\Client\Weaviate;
use Weaviate\Client\WeaviateClient;

final class WeaviateTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function cloudUrls(): iterable
    {
        yield 'bare host' => ['abc123.c0.europe-west3.gcp.weaviate.cloud', 'abc123.c0.europe-west3.gcp.weaviate.cloud', 'grpc-abc123.c0.europe-west3.gcp.weaviate.cloud'];
        yield 'pasted URL' => ['https://abc123.weaviate.cloud/', 'abc123.weaviate.cloud', 'grpc-abc123.weaviate.cloud'];
        yield 'legacy WCS domain' => ['my-cluster.weaviate.network', 'my-cluster.weaviate.network', 'my-cluster.grpc.weaviate.network'];
    }

    #[DataProvider('cloudUrls')]
    public function testCloudUrlParsing(string $input, string $httpHost, string $grpcHost): void
    {
        self::assertSame([$httpHost, $grpcHost], Weaviate::parseCloudUrl($input));
    }

    public function testHeadersAreLowercasedAndForwardedAsGrpcMetadata(): void
    {
        $client = new WeaviateClient(
            ConnectionParams::fromParams('localhost', 8080, false, 'localhost', 50051, false),
            auth: 'secret-key',
            headers: ['X-OpenAI-Api-Key' => 'sk-test'],
        );

        self::assertSame([
            'x-weaviate-client' => 'weaviate-client-php/' . Version::CLIENT . '-sync',
            'x-openai-api-key' => 'sk-test',
            'authorization' => 'Bearer secret-key',
        ], $client->grpcMetadata());
    }

    public function testClusterUrlHeaderOnlyForWeaviateDomains(): void
    {
        $cloud = new WeaviateClient(ConnectionParams::fromParams('abc.weaviate.cloud', 443, true, 'grpc-abc.weaviate.cloud', 443, true));
        $local = new WeaviateClient(ConnectionParams::fromParams('localhost', 8080, false, 'localhost', 50051, false));

        self::assertSame('https://abc.weaviate.cloud', $cloud->grpcMetadata()['x-weaviate-cluster-url'] ?? null);
        self::assertArrayNotHasKey('x-weaviate-cluster-url', $local->grpcMetadata());
    }

    public function testAuthCredentialsWinOverAnAuthorizationHeader(): void
    {
        $client = new WeaviateClient(
            ConnectionParams::fromParams('localhost', 8080, false, 'localhost', 50051, false),
            auth: Auth::apiKey('from-auth'),
            headers: ['Authorization' => 'Bearer from-header'],
        );

        self::assertSame('Bearer from-auth', $client->grpcMetadata()['authorization']);
    }

    public function testNullHeaderValuesAreRejected(): void
    {
        $this->expectException(InvalidInputException::class);
        new WeaviateClient(ConnectionParams::fromParams('localhost', 8080, false, 'localhost', 50051, false), headers: ['X-Key' => null]);
    }

    public function testServerVersionComparison(): void
    {
        $version = ServerVersion::parse('1.39.7-rc.1');

        self::assertSame('1.39.7', (string) $version);
        self::assertTrue($version->isAtLeastVersion(Version::MIN_SERVER));
        self::assertTrue($version->isAtLeast(1, 39, 7));
        self::assertFalse($version->isAtLeast(1, 39, 8));
        self::assertFalse(ServerVersion::parse('1.28.4')->isAtLeastVersion(Version::MIN_SERVER));
    }
}
