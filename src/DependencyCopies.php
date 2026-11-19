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
        foreach ($this->entries as $entry) {
            $hashes[$this->key($entry)][$entry['sha256']] = true;
        }
        foreach ($this->entries as $entry) {
            $name = (count($hashes[$this->key($entry)]) > 1 ? substr($entry['sha256'], 0, 8) . '-' : '') . $entry['name'];
            $path = $this->folder($entry) . '/' . $name;
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
        // Git on macOS and Windows does not record a change to a file name that is only in case.
        $clean = strtolower(preg_replace('/[^A-Za-z0-9._-]/u', '-', $name) ?? preg_replace('/[^A-Za-z0-9._-]/', '-', $name) ?? '');
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
        return $this->folder($entry) . '/' . $entry['name'];
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
