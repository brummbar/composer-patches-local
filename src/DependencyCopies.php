<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal;

use cweagans\Composer\Patch;

/**
 * Writes the copies of dependency patches into the root project and points the patches at them.
 */
final class DependencyCopies
{
    /** @var list<array{patch: Patch, declaring: string, source: string, name: string, content: string, sha256: string}> */
    private array $entries = [];

    public function __construct(private readonly string $projectDirectory)
    {
    }

    /**
     * @param string $rawName
     *   The file name of the patch, before the plugin cleans it.
     */
    public function add(Patch $patch, string $declaringPackage, string $rawName, string $content): void
    {
        $this->entries[] = [
            'patch' => $patch,
            'declaring' => $declaringPackage,
            'source' => $patch->url,
            'name' => self::cleanName($rawName),
            'content' => $content,
            'sha256' => hash('sha256', $content),
        ];
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
     * Writes the copies that changed, and points each patch at its copy.
     */
    public function publish(): void
    {
        $hashes = [];
        $names = [];
        foreach ($this->entries as $entry) {
            $key = $this->key($entry);
            $hashes[$key][$entry['sha256']] = true;
            $names[$key][$entry['sha256']] ??= $entry['name'];
        }
        foreach ($this->entries as $entry) {
            $key = $this->key($entry);
            $name = (count($hashes[$key]) > 1 ? substr($entry['sha256'], 0, 8) . '-' : '') . $names[$key][$entry['sha256']];
            $path = $this->folder($entry) . '/' . $name;
            $this->removeCaseVariants($this->projectDirectory . '/' . $path);
            $this->write($this->projectDirectory . '/' . $path, $entry['content']);

            $patch = $entry['patch'];
            $patch->url = $path;
            // A dependency must not control the patcher, so its other extra keys go away.
            $patch->extra = [
                'provenance' => 'dependency:' . $entry['declaring'],
                'source' => $entry['source'],
            ];
        }
        $this->entries = [];
    }

    private static function cleanName(string $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/u', '-', $name) ?? preg_replace('/[^A-Za-z0-9._-]/', '-', $name) ?? '';
        if ($clean === '') {
            return 'patch.patch';
        }

        return preg_match('/\.(patch|diff)$/', $clean) ? $clean : $clean . '.patch';
    }

    /**
     * @param array{patch: Patch, declaring: string, name: string} $entry
     */
    private function folder(array $entry): string
    {
        return PatchPath::COPY_FOLDER . '/' . $entry['declaring'] . '/' . $entry['patch']->package;
    }

    /**
     * @param array{patch: Patch, declaring: string, name: string} $entry
     */
    private function key(array $entry): string
    {
        // On macOS and Windows, two names that differ only in case point to the same file. Entries with one key and the same content get one name.
        return strtolower($this->folder($entry) . '/' . $entry['name']);
    }

    /**
     * Deletes the files that have the name of the copy, except for the case.
     */
    private function removeCaseVariants(string $file): void
    {
        $directory = dirname($file);
        $names = is_dir($directory) ? scandir($directory) : false;
        foreach ($names ?: [] as $name) {
            if ($name !== basename($file) && strcasecmp($name, basename($file)) === 0 && is_file($directory . '/' . $name)) {
                // A file system that ignores case would keep the old name when it writes the new copy.
                unlink($directory . '/' . $name);
            }
        }
    }

    private function write(string $file, string $content): void
    {
        if (is_file($file) && file_get_contents($file) === $content) {
            return;
        }
        $directory = dirname($file);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException("The folder $directory cannot be created.");
        }
        if (file_put_contents($file, $content) === false) {
            throw new \RuntimeException("The file $file cannot be written.");
        }
    }
}
