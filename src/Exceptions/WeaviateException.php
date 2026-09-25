<?php

declare(strict_types=1);

namespace Weaviate\Client\Exceptions;

/**
 * Base class for every exception the client throws. Catch this to handle any client failure.
 */
class WeaviateException extends \RuntimeException {}
