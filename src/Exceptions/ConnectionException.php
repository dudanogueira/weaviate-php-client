<?php

declare(strict_types=1);

namespace Weaviate\Client\Exceptions;

/**
 * Network, TLS or protocol failure talking to Weaviate, or a missing transport capability (e.g. curl without HTTP/2).
 */
class ConnectionException extends WeaviateException {}
