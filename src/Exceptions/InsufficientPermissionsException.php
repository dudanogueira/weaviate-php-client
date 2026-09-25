<?php

declare(strict_types=1);

namespace Weaviate\Client\Exceptions;

/**
 * The credentials are valid but lack the RBAC permission for the operation (HTTP 403 / gRPC PERMISSION_DENIED).
 * Python `InsufficientPermissionsError`, which likewise subclasses the unexpected-status error, so
 * `catch (UnexpectedStatusCodeException)` also catches it. gRPC denials are reported with status code 403.
 */
class InsufficientPermissionsException extends UnexpectedStatusCodeException {}
