<?php

declare(strict_types=1);

namespace Weaviate\Client\Connect;

use Weaviate\Client\Exceptions\InvalidInputException;

/**
 * Timeouts in seconds per class of operation. Python `Timeout`; see docs/09-connection.md §4.1.
 */
final readonly class Timeout
{
    public function __construct(
        public int|float $query = 30,
        public int|float $insert = 90,
        public int|float $init = 2,
        public int|float|null $stream = null,
    ) {
        foreach (['query' => $query, 'insert' => $insert, 'init' => $init, 'stream' => $stream ?? 0] as $name => $value) {
            if ($value < 0) {
                throw new InvalidInputException(\sprintf('timeout %s must be >= 0, got %s', $name, $value));
            }
        }
    }

    /**
     * Python accepts a `(query, insert)` tuple; PHP accepts `[query, insert]`.
     *
     * @param self|array<int|float> $timeout
     */
    public static function from(self|array $timeout): self
    {
        if ($timeout instanceof self) {
            return $timeout;
        }
        if (!array_is_list($timeout) || \count($timeout) !== 2) {
            throw new InvalidInputException('timeout array must be [query, insert]');
        }

        return new self(query: $timeout[0], insert: $timeout[1]);
    }
}
