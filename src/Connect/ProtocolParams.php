<?php

declare(strict_types=1);

namespace Weaviate\Client\Connect;

use Weaviate\Client\Exceptions\InvalidInputException;

/**
 * One endpoint (REST or gRPC): host, port and whether it uses TLS. Python `ProtocolParams`.
 *
 * The host is a DNS name, an IPv4 address or an IPv6 address (with or without brackets); it's stored
 * lowercase and without brackets. authority() renders it for URLs.
 */
final readonly class ProtocolParams
{
    public string $host;

    public function __construct(
        string $host,
        public int $port,
        public bool $secure,
    ) {
        $host = strtolower(trim($host));
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if ($host === '') {
            throw new InvalidInputException('host must not be empty');
        }
        $isIpv6 = str_contains($host, ':');
        if ($isIpv6 ? filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6) === false : preg_match('/^[a-z0-9._-]+$/', $host) !== 1) {
            throw new InvalidInputException(\sprintf(
                "Invalid host '%s': pass a host name or IP address only, without scheme, port or path.",
                $host,
            ));
        }
        if ($port < 1 || $port > 65535) {
            throw new InvalidInputException(\sprintf('port must be between 1 and 65535, got %d', $port));
        }
        $this->host = $host;
    }

    /**
     * `host:port`, with IPv6 hosts in brackets.
     */
    public function authority(): string
    {
        return (str_contains($this->host, ':') ? '[' . $this->host . ']' : $this->host) . ':' . $this->port;
    }

    public function url(): string
    {
        return ($this->secure ? 'https' : 'http') . '://' . $this->authority();
    }

    public function sameEndpointAs(self $other): bool
    {
        return $this->host === $other->host && $this->port === $other->port;
    }
}
