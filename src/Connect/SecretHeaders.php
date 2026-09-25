<?php

declare(strict_types=1);

namespace Weaviate\Client\Connect;

/**
 * Holds request headers, which include credentials (`authorization`, provider API keys), without exposing
 * them to var_dump, print_r, var_export, `(array)` casts, json_encode or serialize.
 *
 * The values live in a private static WeakMap keyed by this object, not in a property, so no dump or cast of
 * this object (or of anything holding it) contains them. Security review S4.
 *
 * @internal
 */
final class SecretHeaders implements \JsonSerializable
{
    /** @var \WeakMap<self, array<string, string>>|null */
    private static ?\WeakMap $store = null;

    /**
     * @param array<string, string> $headers lowercase names, already validated by Headers::normalize()
     */
    public function __construct(#[\SensitiveParameter] array $headers = [])
    {
        self::$store ??= new \WeakMap();
        self::$store[$this] = $headers;
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return self::$store[$this] ?? [];
    }

    public function with(string $name, #[\SensitiveParameter] string $value): self
    {
        return new self([...$this->all(), ...Headers::normalize([$name => $value])]);
    }

    public function without(string $name): self
    {
        $headers = $this->all();
        unset($headers[strtolower($name)]);

        return new self($headers);
    }

    /**
     * @return array<string, string>
     */
    public function redacted(): array
    {
        return Headers::redact($this->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return $this->redacted();
    }

    /**
     * @return array<string, string>
     */
    public function jsonSerialize(): array
    {
        return $this->redacted();
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('Headers containing credentials cannot be serialized');
    }

    /** A clone would lose the values (they aren't properties). */
    private function __clone() {}
}
