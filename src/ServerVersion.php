<?php

declare(strict_types=1);

namespace Weaviate\Client;

use Weaviate\Client\Exceptions\UnsupportedFeatureException;

/**
 * A Weaviate server version, parsed from /v1/meta (e.g. "1.39.7" or "1.40.0-rc.1").
 */
final readonly class ServerVersion implements \Stringable
{
    public function __construct(
        public int $major,
        public int $minor,
        public int $patch,
        public string $raw = '',
    ) {}

    public static function parse(string $version): self
    {
        if (preg_match('/^v?(\d+)\.(\d+)(?:\.(\d+))?/', $version, $m) !== 1) {
            return new self(0, 0, 0, $version);
        }

        return new self((int) $m[1], (int) $m[2], (int) ($m[3] ?? 0), $version);
    }

    public function isAtLeast(int $major, int $minor, int $patch = 0): bool
    {
        return [$this->major, $this->minor, $this->patch] >= [$major, $minor, $patch];
    }

    public function isAtLeastVersion(string $version): bool
    {
        $other = self::parse($version);

        return $this->isAtLeast($other->major, $other->minor, $other->patch);
    }

    /**
     * @throws UnsupportedFeatureException
     */
    public function require(string $minimum, string $feature): void
    {
        if (!$this->isAtLeastVersion($minimum)) {
            throw new UnsupportedFeatureException($feature, (string) $this, $minimum);
        }
    }

    public function __toString(): string
    {
        return \sprintf('%d.%d.%d', $this->major, $this->minor, $this->patch);
    }
}
