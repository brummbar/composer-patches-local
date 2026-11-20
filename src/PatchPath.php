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

    public const URL_PROBLEM = 'the patch is a URL';

    public const SCHEME_PATTERN = '[a-z][a-z0-9+.-]*';

    /**
     * Returns the path of patches/dependencies.
     * Returns null when the folder does not exist, or when patches/ or patches/dependencies/ is a link.
     */
    public static function copyFolder(string $projectDirectory): ?string
    {
        $folder = $projectDirectory . '/' . self::COPY_FOLDER;
        if (is_link($projectDirectory . '/' . self::FOLDER) || is_link($folder) || !is_dir($folder)) {
            return null;
        }

        return $folder;
    }

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
            return self::URL_PROBLEM;
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

    /**
     * Returns the last segment of the path of a URL, without query and fragment.
     */
    public static function urlName(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) ? basename($path) : '';
    }

    /**
     * Returns a lowercase file name with only safe characters, that ends with .patch or .diff.
     */
    public static function cleanName(string $name): string
    {
        // Replace each character that is not permitted with a dash.
        // The "u" flag reads the name as UTF-8, so "é" gives one dash and not two.
        // The flag fails for a name that is not valid UTF-8. Then the second pattern works on bytes.
        $clean = preg_replace('/[^A-Za-z0-9._-]/u', '-', $name)
            ?? preg_replace('/[^A-Za-z0-9._-]/', '-', $name)
            ?? '';
        // Git on macOS and Windows does not record a change to a file name that is only in case.
        $clean = strtolower($clean);
        if ($clean === '') {
            return 'patch.patch';
        }

        return preg_match('/\.(patch|diff)$/', $clean) ? $clean : $clean . '.patch';
    }
}
