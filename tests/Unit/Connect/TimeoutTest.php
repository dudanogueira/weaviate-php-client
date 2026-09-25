<?php

declare(strict_types=1);

namespace Weaviate\Client\Tests\Unit\Connect;

use PHPUnit\Framework\TestCase;
use Weaviate\Client\Connect\Timeout;
use Weaviate\Client\Exceptions\InvalidInputException;

final class TimeoutTest extends TestCase
{
    public function testDefaultsMatchPython(): void
    {
        $timeout = new Timeout();

        self::assertSame([30, 90, 2, null], [$timeout->query, $timeout->insert, $timeout->init, $timeout->stream]);
    }

    public function testArrayIsQueryAndInsert(): void
    {
        $timeout = Timeout::from([10, 20]);

        self::assertSame([10, 20, 2], [$timeout->query, $timeout->insert, $timeout->init]);
    }

    public function testNegativeValuesAreRejected(): void
    {
        $this->expectException(InvalidInputException::class);
        new Timeout(query: -1);
    }

    public function testZeroIsRejectedBecauseTransportsDisagreeOnItsMeaning(): void
    {
        $this->expectException(InvalidInputException::class);
        new Timeout(init: 0);
    }

    public function testArrayMustBeAQueryInsertPair(): void
    {
        $this->expectException(InvalidInputException::class);
        Timeout::from(['query' => 1, 'insert' => 2]);
    }
}
