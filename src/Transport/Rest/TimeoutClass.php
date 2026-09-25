<?php

declare(strict_types=1);

namespace Weaviate\Client\Transport\Rest;

use Weaviate\Client\Connect\Timeout;

/**
 * Which Timeout value a request uses (docs/09-connection.md §4.1).
 *
 * @internal
 */
enum TimeoutClass
{
    case Init;
    case Query;
    case Insert;

    public function seconds(Timeout $timeout): float
    {
        return (float) match ($this) {
            self::Init => $timeout->init,
            self::Query => $timeout->query,
            self::Insert => $timeout->insert,
        };
    }
}
