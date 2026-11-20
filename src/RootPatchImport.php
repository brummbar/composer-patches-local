<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal;

use Composer\Composer;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Util\Url;
use cweagans\Composer\Patch;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Downloads the remote patches of the root project into its patches/ folder.
 * It points the patch definitions of the root project at the new files.
 */
final class RootPatchImport
{
    private const JSON_OPTIONS = JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE;

    public function __construct(private readonly Composer $composer, private readonly IOInterface $io)
    {
    }

    public function run(): void
    {
        $patches = ComposerPatches::find($this->composer);
        $directory = ComposerPatches::projectDirectory($patches);
        $composerFile = Factory::getComposerFile();
        $sources = [self::readSource($composerFile, true)];
        $patchesFile = $patches->getConfig('patches-file');
        if (is_string($patchesFile) && is_file($patchesFile)) {
            $sources[] = self::readSource($patchesFile, false);
        }

        $imports = [];
        foreach ($sources as $index => $source) {
            foreach (self::remoteEntries($source['patches']) as $entry) {
                $imports[] = $entry + ['source' => $index];
            }
        }
        if ($imports === []) {
            $this->io->writeError('composer-patches-local: the root project has no remote patches.');

            return;
        }

        $allowed = AllowedSchemes::fromRoot($this->composer->getPackage());
        foreach ($imports as $import) {
            $scheme = (string) PatchPath::urlScheme($import['url']);
            if (!in_array($scheme, $allowed, true)) {
                $url = Url::sanitize($import['url']);
                throw new \RuntimeException(
                    "composer-patches-local: the patch \"$url\" of {$import['package']} has the scheme \"$scheme\". The scheme is not in \"allowed-schemes\" in extra.composer-patches-local of the root composer.json.\n"
                    . 'The allowed schemes are: ' . implode(', ', $allowed) . ".\n"
                    . "Copy the file into the patches/ folder by hand, and change its url.\n"
                    . 'No file was changed.'
                );
            }
        }

        // Download everything first, so that a failure leaves all files as they are.
        foreach ($imports as $key => $import) {
            $patch = new Patch();
            $patch->package = $import['package'];
            $patch->url = $import['url'];
            $patch->description = $import['description'];
            if ($import['sha256'] !== null) {
                $patch->sha256 = $import['sha256'];
            }
            $patches->download($patch);
            $content = (string) file_get_contents((string) $patch->localPath);
            if ($import['sha256'] !== null && hash('sha256', $content) !== $import['sha256']) {
                $url = Url::sanitize($import['url']);
                throw new \RuntimeException(
                    "composer-patches-local: the patch \"$url\" of {$import['package']} does not match its sha256.\n"
                    . 'No file was changed.'
                );
            }
            $imports[$key]['content'] = $content;
        }

        $files = [];
        foreach ($imports as $key => $import) {
            $imports[$key]['path'] = $this->targetPath($import, $directory, $files);
        }

        foreach ($files as $path => $content) {
            self::write($directory . '/' . $path, $content);
        }
        foreach ($imports as $import) {
            ($import['set'])($import['path']);
        }
        $this->writeSources($sources, array_values(array_unique(array_column($imports, 'source'))));

        $this->io->writeError('<info>composer-patches-local: downloaded these remote patches into the patches/ folder:</info>');
        foreach ($imports as $import) {
            $this->io->writeError(
                '  - <info>' . OutputFormatter::escape($import['path']) . '</info>'
                . ' (from ' . OutputFormatter::escape(Url::sanitize($import['url'])) . ')'
            );
        }
        $this->io->writeError('Run composer install to update patches.lock.json.');
    }

    /**
     * @return array{file: string, raw: string, data: object, patches: object|null}
     */
    private static function readSource(string $file, bool $inExtra): array
    {
        $raw = (string) file_get_contents($file);
        $data = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        if (!is_object($data)) {
            throw new \RuntimeException("The file $file is not a JSON object.");
        }
        $container = $inExtra ? ($data->extra ?? null) : $data;
        $patches = is_object($container) ? ($container->patches ?? null) : null;

        return [
            'file' => $file,
            'raw' => $raw,
            'data' => $data,
            'patches' => is_object($patches) ? $patches : null,
        ];
    }

    /**
     * Returns the remote entries of a patch definition.
     * The closure of an entry writes the new path into the data.
     *
     * @return list<array{package: string, url: string, description: string, sha256: string|null, set: \Closure(string): void}>
     */
    private static function remoteEntries(?object $patches): array
    {
        $entries = [];
        foreach ($patches === null ? [] : get_object_vars($patches) as $package => $definitions) {
            $package = (string) $package;
            if (is_array($definitions) && isset($definitions[0]) && is_object($definitions[0])) {
                foreach ($definitions as $definition) {
                    if (!is_object($definition)) {
                        continue;
                    }
                    $url = $definition->url ?? null;
                    if (!is_string($url) || PatchPath::urlScheme($url) === null) {
                        continue;
                    }
                    $description = $definition->description ?? '';
                    $sha256 = $definition->sha256 ?? null;
                    $entries[] = [
                        'package' => $package,
                        'url' => $url,
                        'description' => is_string($description) ? $description : '',
                        'sha256' => is_string($sha256) && $sha256 !== '' ? $sha256 : null,
                        'set' => static function (string $path) use ($definition): void {
                            $definition->url = $path;
                        },
                    ];
                }
            } elseif (is_array($definitions)) {
                foreach ($definitions as $index => $url) {
                    if (is_string($url) && PatchPath::urlScheme($url) !== null) {
                        $entries[] = [
                            'package' => $package,
                            'url' => $url,
                            'description' => (string) $index,
                            'sha256' => null,
                            'set' => static function (string $path) use ($patches, $package, $index): void {
                                $current = $patches->{$package};
                                if (is_array($current)) {
                                    $current[$index] = $path;
                                    $patches->{$package} = $current;
                                }
                            },
                        ];
                    }
                }
            } elseif (is_object($definitions)) {
                foreach (get_object_vars($definitions) as $description => $url) {
                    if (is_string($url) && PatchPath::urlScheme($url) !== null) {
                        $entries[] = [
                            'package' => $package,
                            'url' => $url,
                            'description' => (string) $description,
                            'sha256' => null,
                            'set' => static function (string $path) use ($definitions, $description): void {
                                $definitions->{$description} = $path;
                            },
                        ];
                    }
                }
            }
        }

        return $entries;
    }

    /**
     * Returns the path for an entry and adds a new file to $files.
     * Never uses a path that holds other content.
     *
     * @param array{package: string, url: string, content: string} $import
     * @param array<string, string> $files
     *   The new files of this run: path => content.
     */
    private function targetPath(array $import, string $directory, array &$files): string
    {
        $sha256 = hash('sha256', $import['content']);
        $name = PatchPath::cleanName(PatchPath::urlName($import['url']));
        $folder = PatchPath::FOLDER . '/' . $import['package'] . '/';
        foreach ([$folder . $name, $folder . substr($sha256, 0, 8) . '-' . $name] as $path) {
            if (PatchPath::problem($path) !== null) {
                throw new \RuntimeException("composer-patches-local: the package name \"{$import['package']}\" gives the unsafe path $path.");
            }
            $existing = $files[$path] ?? (is_file($directory . '/' . $path) ? (string) file_get_contents($directory . '/' . $path) : null);
            if ($existing === null) {
                $files[$path] = $import['content'];

                return $path;
            }
            if ($existing === $import['content']) {
                return $path;
            }
        }

        throw new \RuntimeException(
            "composer-patches-local: the files $folder$name and $path hold other content, so the plugin cannot import "
            . Url::sanitize($import['url']) . ".\n"
            . 'No file was changed.'
        );
    }

    /**
     * @param list<array{file: string, raw: string, data: object, patches: object|null}> $sources
     *   Source 0 is composer.json.
     * @param list<int> $changed
     *   The indexes of the sources that an import changed.
     */
    private function writeSources(array $sources, array $changed): void
    {
        $locker = $this->composer->getLocker();
        $updateHash = in_array(0, $changed, true)
            && $this->composer->getConfig()->get('lock')
            && $locker->isLocked()
            && $locker->isFresh();
        foreach ($changed as $index) {
            self::write($sources[$index]['file'], self::encode($sources[$index]['data'], $sources[$index]['raw']));
        }
        if ($updateHash) {
            $locker->updateHash(new JsonFile($sources[0]['file']));
        }
    }

    private static function encode(object $data, string $raw): string
    {
        return JsonFile::encode($data, self::JSON_OPTIONS, JsonFile::detectIndenting($raw)) . "\n";
    }

    private static function write(string $file, string $content): void
    {
        $directory = dirname($file);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException("The folder $directory cannot be created.");
        }
        if (file_put_contents($file, $content) === false) {
            throw new \RuntimeException("The file $file cannot be written.");
        }
    }
}
