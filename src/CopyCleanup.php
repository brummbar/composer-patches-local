<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal;

/**
 * Deletes copies in patches/dependencies/ that the lock does not use.
 */
final class CopyCleanup
{
    /**
     * @param list<string> $referencedPaths
     *   The patch paths of patches.lock.json, relative to the project.
     */
    public static function run(string $projectDirectory, array $referencedPaths): void
    {
        $root = $projectDirectory . '/' . PatchPath::COPY_FOLDER;
        if (is_link($root) || !is_dir($root)) {
            return;
        }
        $keep = [];
        foreach ($referencedPaths as $path) {
            $keep[PatchPath::normalise($path) ?? $path] = true;
        }
        self::clean($root, PatchPath::COPY_FOLDER, $keep);
        if (count(scandir($root) ?: []) === 2) {
            rmdir($root);
        }
    }

    /**
     * @param array<string, true> $keep
     */
    private static function clean(string $directory, string $relative, array $keep): void
    {
        foreach (scandir($directory) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . '/' . $item;
            $itemRelative = $relative . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                self::clean($path, $itemRelative, $keep);
                if (count(scandir($path) ?: []) === 2) {
                    rmdir($path);
                }
            } elseif (!isset($keep[$itemRelative])) {
                unlink($path);
            }
        }
    }
}
