<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal\Tests\Support;

use Symfony\Component\Process\Process;

/**
 * @phpstan-type PatchesLock array{patches: array<string, list<array{url: string, sha256?: string, extra: array{provenance: string}, ...}>>, 'composer-patches-local'?: string, ...}
 *
 * A root project in a temporary directory, with fixture packages around it.
 *
 * All packages are path repositories that Composer copies (no symlinks).
 * Packagist is off. The cweagans packages come from the vendor folder of this repository.
 */
final class ComposerProject
{
    private const UPSTREAM_PACKAGES = [
        'cweagans/composer-patches',
        'cweagans/composer-configurable-plugin',
    ];

    public readonly string $directory;

    private FixturePackage $root;

    /** @var array<string, string> Package name => directory. */
    private array $packageDirectories = [];

    /** @var array<string, array{directory: string, version: string}> Released updates of upstream packages and of this plugin. */
    private array $upstreamUpdates = [];

    private bool $withPlugin = false;

    private bool $disableDependencyResolver = false;

    /** @var array<string, string> Fixture packages of type composer-plugin. */
    private array $pluginPackages = [];

    /** @var array<string, string|list<string>> */
    private array $scripts = [];

    private string $cacheDirectory;

    /** @var array<string, mixed> */
    private array $rootConfig = ['secure-http' => false];

    public function __construct()
    {
        $this->directory = sys_get_temp_dir() . '/cpl-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/project', 0777, true);
        mkdir($this->directory . '/home', 0777, true);
        $this->cacheDirectory = $this->directory . '/cache';
        mkdir($this->cacheDirectory, 0777, true);
        $this->root = new FixturePackage('test/root');
    }

    public function projectPath(string $relativePath = ''): string
    {
        return $this->directory . '/project' . ($relativePath === '' ? '' : '/' . $relativePath);
    }

    /**
     * Writes a fixture package. Call it again to release a new version.
     */
    public function addPackage(FixturePackage $package): self
    {
        $directory = $this->directory . '/packages/' . str_replace('/', '-', $package->name);
        FileSystem::remove($directory);
        FileSystem::write($directory . '/composer.json', self::encode($package->composerData()));
        foreach ($package->files as $path => $content) {
            FileSystem::write($directory . '/' . $path, $content);
        }
        $this->packageDirectories[$package->name] = $directory;
        if ($package->type === 'composer-plugin') {
            $this->pluginPackages[$package->name] = $package->name;
        }
        return $this;
    }

    /**
     * Releases the next patch version of an upstream package, as a copy of the installed one.
     */
    public function releaseUpstreamUpdate(string $name): self
    {
        $parts = explode('.', self::upstreamPackages()[$name]);
        $parts[count($parts) - 1] = (string) ((int) end($parts) + 1);
        $version = implode('.', $parts);
        $directory = $this->directory . '/packages/' . str_replace('/', '-', $name) . '-' . $version;
        FileSystem::remove($directory);
        FileSystem::copyDirectory(dirname(__DIR__, 2) . '/vendor/' . $name, $directory);
        $this->upstreamUpdates[$name] = ['directory' => $directory, 'version' => $version];
        return $this;
    }

    /**
     * Releases a new version of this plugin, with the same code.
     */
    public function releasePluginUpdate(string $version): self
    {
        $this->upstreamUpdates[PluginCopy::NAME] = ['directory' => PluginCopy::path(), 'version' => $version];
        return $this;
    }

    /**
     * Sets the root project. Its files go into the project directory.
     */
    public function setRoot(FixturePackage $root): self
    {
        $this->root = $root;
        foreach ($root->files as $path => $content) {
            FileSystem::write($this->projectPath($path), $content);
        }
        return $this;
    }

    /**
     * Adds this plugin to the root project, from a clean copy.
     *
     * @param bool $disableDependencyResolver
     *   True to add the setting that turns off the dependency resolver of composer-patches.
     */
    public function withPlugin(bool $disableDependencyResolver = true): self
    {
        $this->withPlugin = true;
        $this->disableDependencyResolver = $disableDependencyResolver;
        return $this;
    }

    /**
     * Sets the scripts of the root project.
     *
     * @param array<string, string|list<string>> $scripts
     */
    public function setScripts(array $scripts): self
    {
        $this->scripts = $scripts;
        return $this;
    }

    /**
     * @param list<string> $arguments
     *   Composer arguments, for example ['install'].
     * @param array<string, string> $environment
     */
    public function composer(array $arguments, array $environment = []): ComposerResult
    {
        $this->writeRootComposerJson();
        $binary = getenv('COMPOSER_BINARY') ?: 'composer';
        $command = $binary . ' --no-interaction --no-ansi ' . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1';
        $process = Process::fromShellCommandline($command, $this->projectPath(), $environment + [
            'COMPOSER_CACHE_DIR' => $this->cacheDirectory,
            'COMPOSER_HOME' => $this->directory . '/home',
            // A wide terminal stops Composer from wrapping error messages.
            'COLUMNS' => '300',
        ], null, 300);
        $process->run();
        return new ComposerResult($process->getExitCode() ?? -1, $process->getOutput(), $command);
    }

    public function readFile(string $relativePath): string
    {
        $path = $this->projectPath($relativePath);
        if (!is_file($path)) {
            throw new \RuntimeException("File not found in project: $relativePath");
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Cannot read file in project: $relativePath");
        }

        return $content;
    }

    /**
     * @return PatchesLock
     */
    public function readPatchesLock(): array
    {
        /** @var PatchesLock */
        return json_decode($this->readFile('patches.lock.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Returns the files below a project folder: relative path => content, sorted by path.
     *
     * @return array<string, string>
     */
    public function tree(string $relativeDirectory): array
    {
        $base = $this->projectPath($relativeDirectory);
        $files = [];
        if (is_dir($base)) {
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            /** @var \SplFileInfo $item */
            foreach ($items as $item) {
                $files[$items->getSubPathname()] = (string) file_get_contents($item->getPathname());
            }
        }
        ksort($files);
        return $files;
    }

    /**
     * Makes a new project with the same packages, an empty cache and no vendor folder.
     *
     * It gets only the given files and folders of this project.
     *
     * @param list<string> $paths
     *   Paths relative to the project, for example ['composer.lock', 'patches'].
     */
    public function checkout(array $paths): self
    {
        $copy = new self();
        $copy->root = $this->root;
        $copy->packageDirectories = $this->packageDirectories;
        $copy->upstreamUpdates = $this->upstreamUpdates;
        $copy->withPlugin = $this->withPlugin;
        $copy->disableDependencyResolver = $this->disableDependencyResolver;
        $copy->pluginPackages = $this->pluginPackages;
        $copy->rootConfig = $this->rootConfig;
        foreach ($paths as $path) {
            $source = $this->projectPath($path);
            if (is_dir($source)) {
                FileSystem::copyDirectory($source, $copy->projectPath($path));
            } else {
                FileSystem::write($copy->projectPath($path), (string) file_get_contents($source));
            }
        }
        return $copy;
    }

    public function remove(): void
    {
        FileSystem::remove($this->directory);
    }

    private function writeRootComposerJson(): void
    {
        $repositories = [['packagist.org' => false]];
        // Composer ignores a package in a later repository when an earlier one offers it.
        foreach ($this->upstreamUpdates as $name => $update) {
            $repositories[] = self::pathRepository($name, $update['directory'], $update['version']);
        }
        foreach (self::upstreamPackages() as $name => $version) {
            $repositories[] = self::pathRepository($name, dirname(__DIR__, 2) . '/vendor/' . $name, $version);
        }
        foreach ($this->packageDirectories as $name => $directory) {
            $repositories[] = self::pathRepository($name, $directory);
        }
        $require = $this->root->require + ['cweagans/composer-patches' => '^2.0'];
        $allowPlugins = ['cweagans/composer-patches' => true];
        foreach ($this->pluginPackages as $name) {
            $allowPlugins[$name] = true;
        }
        if ($this->withPlugin) {
            $repositories[] = self::pathRepository(PluginCopy::NAME, PluginCopy::path(), PluginCopy::VERSION);
            $require[PluginCopy::NAME] = '*';
            $allowPlugins[PluginCopy::NAME] = true;
        }
        $data = [
            'name' => $this->root->name,
            'type' => 'project',
            'repositories' => $repositories,
            'require' => $require,
            'config' => $this->rootConfig + ['allow-plugins' => $allowPlugins],
        ];
        $extra = $this->root->extra;
        if ($this->withPlugin && $this->disableDependencyResolver) {
            $settings = (array) ($extra['composer-patches'] ?? []);
            $disabled = (array) ($settings['disable-resolvers'] ?? []);
            $disabled[] = '\\cweagans\\Composer\\Resolver\\Dependencies';
            $settings['disable-resolvers'] = $disabled;
            $extra['composer-patches'] = $settings;
        }
        if ($extra) {
            $data['extra'] = $extra;
        }
        if ($this->scripts !== []) {
            $data['scripts'] = $this->scripts;
        }
        FileSystem::write($this->projectPath('composer.json'), self::encode($data));
    }

    /**
     * @return array<string, string>
     *   Installed version for each upstream package.
     */
    private static function upstreamPackages(): array
    {
        /** @var array{packages: list<array{name: string, version: string}>} $installed */
        $installed = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/vendor/composer/installed.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $versions = [];
        foreach ($installed['packages'] as $package) {
            if (in_array($package['name'], self::UPSTREAM_PACKAGES, true)) {
                $versions[$package['name']] = ltrim($package['version'], 'v');
            }
        }
        return $versions;
    }

    /**
     * @return array<string, mixed>
     */
    private static function pathRepository(string $name, string $url, ?string $version = null): array
    {
        $options = ['symlink' => false];
        if ($version !== null) {
            $options['versions'] = [$name => $version];
        }
        return ['type' => 'path', 'url' => $url, 'options' => $options];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function encode(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }
}
