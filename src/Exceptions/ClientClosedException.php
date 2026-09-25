<?php

declare(strict_types=1);

namespace Weaviate\Client\Exceptions;

/**
 * An operation was attempted on a client that was never connected or has been closed. Python
 * `WeaviateClosedClientError`.
 */
class ClientClosedException extends ConnectionException {}
