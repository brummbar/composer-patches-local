<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal;

/**
 * Rules for the paths of patch files in the root project.
 */
final class PatchPath
{
    public const FOLDER = 'patches';

    public const COPY_FOLDER = 'patches/dependencies';

    public const SCHEME_PATTERN = '[a-z][a-z0-9+.-]*';

    /**
     * Returns the lowercase scheme when the path starts with "<scheme>://", or null.
     */
    public static function urlScheme(string $path): ?string
    {
        if (preg_match('#^(' . self::SCHEME_PATTERN . ')://#i', $path, $matches) !== 1) {
            return null;
        }

        return strtolower($matches[1]);
    }

    /**
     * Returns why a path is not a relative path inside patches/, or null when it is.
     */
    public static function problem(string $path): ?string
    {
        if (self::urlScheme($path) !== null) {
            return 'the patch is a URL';
        }
        $slashed = str_replace('\\', '/', $path);
        if (str_starts_with($slashed, '/') || preg_match('#^[A-Za-z]:#', $slashed)) {
            return 'the patch path is absolute';
        }
        $normalised = self::normalise($slashed);
        if ($normalised === null || !str_starts_with($normalised, self::FOLDER . '/')) {
            return 'the patch is not a file inside ' . self::FOLDER . '/';
        }

        return null;
    }

    /**
     * Removes "." and ".." segments. Returns null when the path leaves its start folder.
     */
    public static function normalise(string $path): ?string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($parts === []) {
                    return null;
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }

        return implode('/', $parts);
    }
}
