<?php

declare(strict_types=1);

namespace Weaviate\Client\Tests\Unit\Transport\Grpc;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Weaviate\Client\Exceptions\ConnectionException;
use Weaviate\Client\Transport\Grpc\Framing;

final class FramingTest extends TestCase
{
    public function testEncodePrefixesFlagAndBigEndianLength(): void
    {
        self::assertSame("\x00\x00\x00\x00\x03abc", Framing::encode('abc'));
        self::assertSame("\x00\x00\x00\x00\x00", Framing::encode(''));
    }

    public function testDecodeRoundTripsSeveralMessages(): void
    {
        $body = Framing::encode('first') . Framing::encode('') . Framing::encode(str_repeat('x', 70_000));

        self::assertSame(['first', '', str_repeat('x', 70_000)], Framing::decode($body, 1_000_000));
    }

    public function testDecodeEmptyBodyHasNoMessages(): void
    {
        self::assertSame([], Framing::decode('', 100));
    }

    public function testDecodeRejectsTruncatedHeader(): void
    {
        $this->expectException(ConnectionException::class);
        Framing::decode("\x00\x00\x00", 100);
    }

    public function testDecodeRejectsTruncatedPayload(): void
    {
        $this->expectException(ConnectionException::class);
        Framing::decode("\x00\x00\x00\x00\x05abc", 100);
    }

    public function testDecodeRejectsCompressedMessages(): void
    {
        $this->expectException(ConnectionException::class);
        Framing::decode("\x01\x00\x00\x00\x01a", 100);
    }

    public function testDecodeRejectsMessagesOverTheLimit(): void
    {
        $this->expectException(ConnectionException::class);
        Framing::decode(Framing::encode('abcdef'), 5);
    }

    /**
     * @return iterable<string, array{float, string}>
     */
    public static function timeouts(): iterable
    {
        yield 'sub-millisecond rounds up to 1m' => [0.0001, '1m'];
        yield 'two seconds' => [2.0, '2000m'];
        yield 'fractional' => [1.2345, '1235m'];
        yield 'just below the 8-digit limit' => [99_999.999, '99999999m'];
        yield 'switches to seconds' => [100_000.0, '100000S'];
    }

    #[DataProvider('timeouts')]
    public function testTimeoutHeaderUsesAtMostEightDigits(float $seconds, string $expected): void
    {
        self::assertSame($expected, Framing::timeoutHeader($seconds));
    }
}
