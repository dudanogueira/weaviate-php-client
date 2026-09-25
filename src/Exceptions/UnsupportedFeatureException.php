<?php

declare(strict_types=1);

namespace Weaviate\Client\Exceptions;

/**
 * The connected server is too old for the requested feature.
 */
class UnsupportedFeatureException extends WeaviateException
{
    public function __construct(
        public readonly string $feature,
        public readonly string $serverVersion,
        public readonly string $minimumVersion,
    ) {
        parent::__construct(\sprintf(
            '%s requires Weaviate %s or higher, but the server is %s',
            $feature,
            $minimumVersion,
            $serverVersion,
        ));
    }
}
