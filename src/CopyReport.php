<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal;

use Composer\IO\IOInterface;
use Composer\Util\Url;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Lists the patch copies that a run wrote or deleted.
 */
final class CopyReport
{
    /**
     * The copies before the first relock of the run: relative path => sha256.
     * The property is static, because a plugin update in the run gives the later events to a new plugin object.
     *
     * @var array<string, string>|null
     */
    private static ?array $before = null;

    /**
     * Takes the snapshot, if the run does not have one yet.
     */
    public static function takeSnapshot(string $projectDirectory): void
    {
        if (self::$before === null) {
            self::$before = self::snapshot($projectDirectory);
        }
    }

    /**
     * Prints the copies that changed since the snapshot, and removes the snapshot.
     *
     * @param array<string, list<array<mixed>>> $lockedPatches
     *   The patches part of patches.lock.json.
     */
    public static function printChanges(IOInterface $io, string $projectDirectory, array $lockedPatches): void
    {
        if (self::$before === null) {
            return;
        }
        [$copied, $deleted] = self::compare(self::$before, self::snapshot($projectDirectory));
        self::$before = null;

        if ($copied !== []) {
            $sources = self::sources($lockedPatches);
            $io->writeError('<info>composer-patches-local: copied these patches from dependencies:</info>');
            foreach ($copied as $path) {
                $source = $sources[$path] ?? null;
                $from = $source === null ? '' : " (from $source)";
                $io->writeError('  - <info>' . OutputFormatter::escape($path) . '</info>' . $from);
            }
        }
        if ($deleted !== []) {
            $io->writeError('<info>composer-patches-local: deleted these patch copies, because no patch uses them now:</info>');
            foreach ($deleted as $path) {
                $io->writeError('  - <info>' . OutputFormatter::escape($path) . '</info>');
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private static function snapshot(string $projectDirectory): array
    {
        $root = $projectDirectory . '/' . PatchPath::COPY_FOLDER;
        if (is_link($root) || !is_dir($root)) {
            return [];
        }
        $files = [];
        self::collect($root, PatchPath::COPY_FOLDER, $files);

        return $files;
    }

    /**
     * @param array<string, string> $files
     */
    private static function collect(string $directory, string $relative, array &$files): void
    {
        foreach (scandir($directory) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . '/' . $item;
            $itemRelative = $relative . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                self::collect($path, $itemRelative, $files);
            } elseif (is_file($path) && !is_link($path) && is_readable($path)) {
                $files[$itemRelative] = hash_file('sha256', $path) ?: '';
            } else {
                // A link or a file that the plugin cannot read gets an empty hash.
                // Then the report still lists its deletion.
                // hash_file() would stop the run for such a file: Composer changes a PHP warning into an error.
                $files[$itemRelative] = '';
            }
        }
    }

    /**
     * @param array<string, string> $before
     * @param array<string, string> $after
     *
     * @return array{list<string>, list<string>}
     *   The sorted paths of new or changed files, and the sorted paths of deleted files.
     */
    private static function compare(array $before, array $after): array
    {
        $copied = [];
        foreach ($after as $path => $hash) {
            if (!isset($before[$path]) || $before[$path] !== $hash) {
                $copied[] = (string) $path;
            }
        }
        $deleted = array_map('strval', array_keys(array_diff_key($before, $after)));
        sort($copied);
        sort($deleted);

        return [$copied, $deleted];
    }

    /**
     * @param array<string, list<array<mixed>>> $lockedPatches
     *
     * @return array<string, string|null>
     *   The source text of each locked patch, keyed by its path.
     */
    private static function sources(array $lockedPatches): array
    {
        $sources = [];
        foreach ($lockedPatches as $list) {
            foreach ($list as $entry) {
                $url = $entry['url'] ?? null;
                if (is_string($url)) {
                    $sources[PatchPath::normalise($url) ?? $url] = self::describeSource($entry);
                }
            }
        }

        return $sources;
    }

    /**
     * @param array<mixed> $entry
     */
    private static function describeSource(array $entry): ?string
    {
        $extra = $entry['extra'] ?? null;
        $source = is_array($extra) ? ($extra['source'] ?? null) : null;
        if (!is_string($source) || $source === '') {
            return null;
        }
        if (PatchPath::urlScheme($source) !== null) {
            return OutputFormatter::escape(Url::sanitize($source));
        }
        $provenance = $extra['provenance'] ?? null;
        if (is_string($provenance) && str_starts_with($provenance, 'dependency:')) {
            $declaring = substr($provenance, strlen('dependency:'));

            return OutputFormatter::escape($source) . ' in ' . OutputFormatter::escape($declaring);
        }

        return OutputFormatter::escape($source);
    }
}
