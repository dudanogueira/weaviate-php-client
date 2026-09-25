<?php

declare(strict_types=1);

namespace Weaviate\Client;

/**
 * Client version, sent in the X-Weaviate-Client header.
 */
final class Version
{
    public const CLIENT = '0.1.0-dev';

    /** Oldest server the client connects to (ADR 0004). */
    public const MIN_SERVER = '1.29.0';
}
