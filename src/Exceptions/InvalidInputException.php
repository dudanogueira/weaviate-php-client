<?php

declare(strict_types=1);

namespace Weaviate\Client\Exceptions;

/**
 * Client-side validation failed. Thrown before any request is sent.
 */
class InvalidInputException extends WeaviateException {}
