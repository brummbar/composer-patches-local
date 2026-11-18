<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal;

use Composer\Package\AliasPackage;
use Composer\Package\Loader\ValidatingArrayLoader;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;
use cweagans\Composer\Patch;
use cweagans\Composer\PatchCollection;
use cweagans\Composer\Resolver\ResolverBase;

/**
 * Collects the patches of locked packages and copies them into the root project.
 * A local path is relative to the declaring package.
 */
final class DependencyResolver extends ResolverBase
{
    /**
     * The plugin rebuilds the lock two times in one run. With a known sha256, composer-patches reads its cache
     * and does not download the same URL again.
     *
     * @var array<string, string>
     */
    private static array $knownHashes = [];

    public function resolve(PatchCollection $collection): void
    {
        $patches = ComposerPatches::find($this->composer);
        ComposerPatches::assertConfigured($patches);

        $locker = $this->composer->getLocker();
        if (!$locker->isLocked()) {
            $this->io->write('  - <info>Composer lock file does not exist. Patches of dependencies are not resolved.</info>');
            return;
        }

        $this->io->write('  - <info>Resolving patches from dependencies.</info>');
        $ignored = $patches->getConfig('ignore-dependency-patches');
        $ignored = is_array($ignored) ? $ignored : [];
        $skipped = $this->plugin instanceof SkippedPackages ? $this->plugin->skippedPackages() : [];
        $projectDirectory = (new Filesystem())->normalizePath(ComposerPatches::projectDirectory($patches));
        $allowedSchemes = AllowedSchemes::fromRoot($this->composer->getPackage());
        $copies = new DependencyCopies($projectDirectory);
        $found = [];
        $lockedTypes = [];
        foreach ($locker->getLockedRepository(true)->getPackages() as $lockedPackage) {
            $lockedTypes[$lockedPackage->getName()] = $lockedPackage->getType();
        }

        foreach ($locker->getLockedRepository()->getPackages() as $package) {
            $extra = $package->getExtra();
            if ($package instanceof AliasPackage || empty($extra['patches']) || in_array($package->getName(), $ignored, true)) {
                continue;
            }
            /** @var array<mixed> $definition */
            $definition = $extra['patches'];
            /** @var array<string, list<Patch>> $grouped */
            $grouped = $this->findPatchesInJson($definition);
            foreach (array_keys($grouped) as $target) {
                if (ValidatingArrayLoader::hasPackageNamingError(strtolower((string) $target)) !== null) {
                    $declaring = $package->getName();
                    throw new \RuntimeException(
                        "composer-patches-local: the package $declaring declares patches for \"$target\". That is not a valid package name.\n"
                        . "To skip the patches of $declaring, add it to \"ignore-dependency-patches\" in extra.composer-patches of the root composer.json."
                    );
                }
            }
            foreach ($grouped as $target => $targetPatches) {
                // composer-patches applies a patch only to a package in composer.lock, and never to its own two packages.
                // It finds a patch only by the exact package name, which is lowercase.
                // Thus, for these targets, the plugin makes no copy and no lock entry.
                if (!isset($lockedTypes[$target]) || in_array($target, Repatch::UPSTREAM, true)) {
                    continue;
                }
                // The patched plugin code runs in Composer, so a dependency must not patch it.
                if (in_array($lockedTypes[$target], Repatch::PLUGIN_TYPES, true)) {
                    $declaring = $package->getName();
                    throw new \RuntimeException(
                        "composer-patches-local: the package $declaring declares patches for the Composer plugin $target. Only the root project can patch a Composer plugin.\n"
                        . "Add $declaring to \"ignore-dependency-patches\" in extra.composer-patches of the root composer.json.\n"
                        . "If you need its patches, copy them to patches/ and declare them in extra.patches of the root composer.json."
                    );
                }
                foreach ($targetPatches as $patch) {
                    $declared = $patch->url;
                    $scheme = PatchPath::urlScheme($declared);
                    $remote = $scheme !== null;
                    if ($remote) {
                        if (!in_array($scheme, $allowedSchemes, true)) {
                            throw $this->schemeError($package, $declared, $scheme, $allowedSchemes);
                        }
                    }
                    if (!$remote) {
                        $this->assertRelativePath($package, $declared);
                        if (in_array($package->getName(), $skipped, true)) {
                            continue;
                        }
                        $content = $this->readLocalPatch($package, $declared, $projectDirectory);
                        $name = basename($declared);
                    } else {
                        if (!isset($patch->sha256) && isset(self::$knownHashes[$declared])) {
                            $patch->sha256 = self::$knownHashes[$declared];
                        }
                        $patches->download($patch);
                        $content = (string) file_get_contents((string) $patch->localPath);
                        // composer-patches must read the copy, not the downloaded file.
                        unset($patch->localPath);
                        $name = DependencyCopies::urlName($declared);
                    }
                    if (isset($patch->sha256) && $patch->sha256 !== hash('sha256', $content)) {
                        throw new \RuntimeException("The patch $declared of {$package->getName()} does not match its sha256.");
                    }
                    $patch->sha256 = hash('sha256', $content);
                    if ($remote) {
                        self::$knownHashes[$declared] = $patch->sha256;
                    }
                    $copies->add($patch, $package->getName(), $name, $content);
                    $found[] = $patch;
                }
            }
        }

        $copies->publish();
        foreach ($found as $patch) {
            $collection->addPatch($patch);
        }
    }

    private function assertRelativePath(PackageInterface $package, string $declared): void
    {
        $filesystem = new Filesystem();
        if ($filesystem->isAbsolutePath($declared)) {
            throw $this->outsideError($package, $declared);
        }
        $normalized = $filesystem->normalizePath($declared);
        if ($normalized === '..' || str_starts_with($normalized, '../')) {
            throw $this->outsideError($package, $declared);
        }
    }

    /**
     * @param list<string> $allowedSchemes
     */
    private function schemeError(PackageInterface $package, string $declared, string $scheme, array $allowedSchemes): \RuntimeException
    {
        $declaring = $package->getName();
        $list = implode(', ', $allowedSchemes);

        return new \RuntimeException(
            "composer-patches-local: the package $declaring declares the patch \"$declared\". "
            . "The scheme \"$scheme\" is not in \"allowed-schemes\" in extra.composer-patches-local of the root composer.json.\n"
            . "The allowed schemes are: $list.\n"
            . $this->skipLine($declaring)
        );
    }

    private function outsideError(PackageInterface $package, string $declared): \RuntimeException
    {
        $declaring = $package->getName();

        return new \RuntimeException(
            "composer-patches-local: the package $declaring declares the patch \"$declared\". "
            . "A local patch of a dependency must be a path inside the folder of the package.\n"
            . $this->skipLine($declaring)
        );
    }

    private function skipLine(string $declaring): string
    {
        return "To skip the patches of $declaring, add it to \"ignore-dependency-patches\" in extra.composer-patches of the root composer.json.";
    }

    /**
     * Returns the content of a patch file of a package. The path is relative to the package folder.
     */
    private function readLocalPatch(PackageInterface $package, string $path, string $projectDirectory): string
    {
        $filesystem = new Filesystem();
        $installPath = $this->composer->getInstallationManager()->getInstallPath($package);
        if ($installPath === null) {
            throw new \RuntimeException("Package {$package->getName()} has no install path, so its local patch $path has no folder.");
        }
        if (!$filesystem->isAbsolutePath($installPath)) {
            $installPath = $projectDirectory . '/' . $installPath;
        }
        $file = $filesystem->normalizePath($installPath . '/' . $path);
        if (!is_file($file)) {
            throw new \RuntimeException("Package {$package->getName()} declares the patch $path, but the file $file does not exist.");
        }
        $realInstall = realpath($installPath);
        $realFile = realpath($file);
        if ($realInstall === false || $realFile === false) {
            throw $this->outsideError($package, $path);
        }
        if (!str_starts_with($filesystem->normalizePath($realFile), $filesystem->normalizePath($realInstall) . '/')) {
            throw $this->outsideError($package, $path);
        }

        return (string) file_get_contents($file);
    }
}
