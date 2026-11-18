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
        $copies = new DependencyCopies($projectDirectory);
        $found = [];
        $lockedNames = [];
        foreach ($locker->getLockedRepository(true)->getPackages() as $lockedPackage) {
            $lockedNames[$lockedPackage->getName()] = true;
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
                // composer-patches applies a patch only to an installed package. Skip the patches for a package that is not in composer.lock.
                if (!isset($lockedNames[strtolower((string) $target)])) {
                    continue;
                }
                foreach ($targetPatches as $patch) {
                    $declared = $patch->url;
                    if ($this->isLocal($declared)) {
                        if (in_array($package->getName(), $skipped, true)) {
                            continue;
                        }
                        $content = $this->readLocalPatch($package, $declared, $projectDirectory);
                        $name = basename($declared);
                    } else {
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

    private function isLocal(string $url): bool
    {
        return !preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) && !(new Filesystem())->isAbsolutePath($url);
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

        return (string) file_get_contents($file);
    }
}
