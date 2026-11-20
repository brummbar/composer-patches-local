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
        $root = PatchPath::copyFolder($projectDirectory);
        if ($root === null) {
            return;
        }
        $keep = [];
        foreach ($referencedPaths as $path) {
            $keep[PatchPath::normalise($path) ?? $path] = true;
        }
        self::clean($root, PatchPath::COPY_FOLDER, $keep);
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
            } elseif (!isset($keep[$itemRelative])) {
                unlink($path);
            }
        }
        if (count(scandir($directory) ?: []) === 2) {
            rmdir($directory);
        }
    }
}
