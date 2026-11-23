<?php

declare(strict_types=1);

namespace Leuffen\JekyllRunner\Helper;

/** @internal Filesystem operations for the fixed build roots. */
final class Directories
{
    public static function root(string $path, bool $create = false): string
    {
        if ($create && !file_exists($path) && !is_link($path) && !mkdir($path, 0755, true)) {
            throw new \RuntimeException("Cannot create directory: $path");
        }
        $real = realpath($path);
        if ($real === false || $real === '/' || !is_dir($real) || !is_readable($real)) {
            throw new \RuntimeException("Directory does not exist or is not readable: $path");
        }
        return rtrim($real, '/');
    }

    /** Resolve a relative path without allowing symlinks or parent traversal. */
    public static function resolve(string $root, string $relative, bool $mustExist): string
    {
        if ($relative === '' || str_contains($relative, '\\') || preg_match('/[\x00-\x1f\x7f]/', $relative)) {
            throw new \InvalidArgumentException("Invalid relative directory: $relative");
        }

        // Jeden Bestandteil prüfen; realpath allein übersieht noch fehlende Targets.
        $path = $root;
        foreach (explode('/', $relative) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || str_starts_with($part, '.')) {
                throw new \InvalidArgumentException("Expected a relative directory without dot components: $relative");
            }
            $path .= '/' . $part;
            clearstatcache(true, $path);
            if (is_link($path)) {
                throw new \InvalidArgumentException("Symbolic links are not allowed in directory paths: $path");
            }
            if (file_exists($path) && !is_dir($path)) {
                throw new \InvalidArgumentException("Path is not a directory: $path");
            }
        }
        if ($mustExist && (!is_dir($path) || !is_readable($path))) {
            throw new \InvalidArgumentException("Source directory does not exist or is not readable: $path");
        }
        return $path;
    }

    public static function create(string $path, int $mode = 0755): void
    {
        clearstatcache(true, $path);
        if (is_link($path) || (file_exists($path) && !is_dir($path))) {
            throw new \RuntimeException("Expected a directory without symbolic links: $path");
        }
        if (!is_dir($path) && !mkdir($path, $mode, true) && !is_dir($path)) {
            throw new \RuntimeException("Cannot create directory: $path");
        }
    }

    /** Generated output must contain only readable regular files/directories. */
    public static function prepareOutput(string $path): void
    {
        if (is_link($path) || (!is_file($path) && !is_dir($path))) {
            throw new \RuntimeException("Jekyll output contains a link or special file: $path");
        }
        $directory = is_dir($path);
        if (!chmod($path, $directory ? 0755 : 0644)) {
            throw new \RuntimeException("Cannot set output permissions: $path");
        }
        if ($directory) {
            foreach (self::children($path) as $child) {
                self::prepareOutput($child);
            }
        }
    }

    /** Delete owned temporary data without following symbolic links. */
    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (!unlink($path)) {
                throw new \RuntimeException("Cannot remove file: $path");
            }
            return;
        }
        if (!file_exists($path)) {
            return;
        }
        if (!is_dir($path)) {
            if (!unlink($path)) {
                throw new \RuntimeException("Cannot remove temporary entry: $path");
            }
            return;
        }
        // Plugins dürfen Zugriffsrechte ändern; eigener temporärer Inhalt bleibt löschbar.
        if (!chmod($path, 0700)) {
            throw new \RuntimeException("Cannot access temporary directory for cleanup: $path");
        }
        foreach (self::children($path) as $child) {
            self::remove($child);
        }
        if (!rmdir($path)) {
            throw new \RuntimeException("Cannot remove directory: $path");
        }
    }

    private static function children(string $path): iterable
    {
        $entries = scandir($path);
        if ($entries === false) {
            throw new \RuntimeException("Cannot read directory: $path");
        }
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                yield $path . '/' . $entry;
            }
        }
    }
}
