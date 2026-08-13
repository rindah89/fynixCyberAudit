<?php

namespace App\Support;

/**
 * Bundles shipped in this repository. No remote catalog.
 */
final class LocalBundleCatalog
{
    /** @return list<array<string, mixed>> */
    public static function entries(): array
    {
        $path = resource_path('bundles/catalog.json');
        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string, mixed>|null */
    public static function find(string $code): ?array
    {
        foreach (self::entries() as $entry) {
            if (($entry['code'] ?? null) === $code) {
                return $entry;
            }
        }

        return null;
    }

    public static function pathFor(string $file): string
    {
        return resource_path('bundles/'.$file);
    }
}
