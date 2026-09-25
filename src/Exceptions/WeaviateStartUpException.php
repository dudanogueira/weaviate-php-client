<?php

declare(strict_types=1);

namespace Weaviate\Client\Exceptions;

/**
 * connect() failed: the server is unreachable, too old (below the 1.29.0 floor), or failed its startup checks.
 */
class WeaviateStartUpException extends WeaviateException {}
