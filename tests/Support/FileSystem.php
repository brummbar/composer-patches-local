<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal\Tests\Support;

/**
 * Small file system helpers for the test harness.
 */
final class FileSystem
{
    public static function write(string $path, string $content): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        file_put_contents($path, $content);
    }

    public static function copyDirectory(string $from, string $to): void
    {
        mkdir($to, 0777, true);
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        /** @var \SplFileInfo $item */
        foreach ($items as $item) {
            $target = $to . '/' . $items->getSubPathname();
            $item->isDir() ? mkdir($target, 0777, true) : copy($item->getPathname(), $target);
        }
    }

    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        /** @var \SplFileInfo $item */
        foreach (new \FilesystemIterator($path) as $item) {
            self::remove($item->getPathname());
        }
        rmdir($path);
    }
}
