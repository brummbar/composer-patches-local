<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal;

use Composer\Package\AliasPackage;
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
        $skipped = $this->plugin instanceof Plugin ? $this->plugin->skippedPackages() : [];
        $projectDirectory = (new Filesystem())->normalizePath(ComposerPatches::projectDirectory($patches));
        $copies = new DependencyCopies($projectDirectory);
        $found = [];

        foreach ($locker->getLockedRepository()->getPackages() as $package) {
            $extra = $package->getExtra();
            if ($package instanceof AliasPackage || empty($extra['patches']) || in_array($package->getName(), $ignored, true)) {
                continue;
            }
            /** @var array<mixed> $definition */
            $definition = $extra['patches'];
            /** @var array<string, list<Patch>> $grouped */
            $grouped = $this->findPatchesInJson($definition);
            foreach ($grouped as $targetPatches) {
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
