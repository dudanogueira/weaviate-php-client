<?php

declare(strict_types=1);

namespace Weaviate\Client\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * ADR 0005: every class lives under Weaviate\Client\, so the package can be installed next to the
 * community package timkley/weaviate-php, which declares classes directly under Weaviate\.
 */
final class NamespaceTest extends TestCase
{
    public function testEverySourceFileIsUnderTheWeaviateClientNamespace(): void
    {
        $root = \dirname(__DIR__, 3) . '/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $offenders = [];
        $checked = 0;

        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match('/^namespace\s+([^;\s]+)\s*;/m', $source, $m) !== 1) {
                $offenders[] = $file->getPathname() . ' (no namespace)';
                continue;
            }
            ++$checked;
            if ($m[1] !== 'Weaviate\Client' && !str_starts_with($m[1], 'Weaviate\Client\\')) {
                $offenders[] = $file->getPathname() . ' (' . $m[1] . ')';
            }
        }

        self::assertGreaterThan(0, $checked);
        self::assertSame([], $offenders);
    }
}
