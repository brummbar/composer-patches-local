<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\Installer\PackageEvent;
use Composer\IO\IOInterface;
use Composer\Package\Package;
use Composer\Package\PackageInterface;
use cweagans\Composer\Patch;

/**
 * Records which patches composer-patches applied, and reinstalls packages whose locked patches differ.
 */
final class Repatch
{
    public const PLUGIN_TYPES = ['composer-plugin', 'composer-installer'];

    public function __construct(
        private readonly Composer $composer,
        private readonly IOInterface $io,
    ) {
    }

    /**
     * Stores a hash of the applied patches in installed.json.
     */
    public function record(PackageEvent $event): void
    {
        $operation = $event->getOperation();
        $package = match (true) {
            $operation instanceof InstallOperation => $operation->getPackage(),
            $operation instanceof UpdateOperation => $operation->getTargetPackage(),
            default => null,
        };
        if ($package === null) {
            return;
        }

        $hash = $this->patchHash($this->patchesFor($package->getName()));
        foreach ($event->getLocalRepo()->getPackages() as $installed) {
            if ($installed instanceof Package && $installed->getName() === $package->getName()) {
                $extra = $installed->getExtra();
                unset($extra[Plugin::KEY]);
                if ($hash !== null) {
                    $extra[Plugin::KEY] = $hash;
                }
                $installed->setExtra($extra);
            }
        }
    }

    /**
     * Reinstalls the packages whose recorded hash differs from the locked patches.
     */
    public function run(bool $devMode): void
    {
        ComposerPatches::find($this->composer)->loadLockedPatches();

        $outdated = [];
        $localRepo = $this->composer->getRepositoryManager()->getLocalRepository();
        foreach ($localRepo->getCanonicalPackages() as $package) {
            $name = $package->getName();
            $expected = $this->patchHash($this->patchesFor($name));
            if (($package->getExtra()[Plugin::KEY] ?? null) === $expected) {
                continue;
            }
            if (in_array($package->getType(), self::PLUGIN_TYPES, true)) {
                $this->io->writeError("<warning>composer-patches-local: $name is a Composer plugin. Its patches changed, but the plugin does not reinstall it. Reinstall it by hand.</warning>");
                continue;
            }
            $outdated[] = $package;
        }
        if ($outdated === []) {
            return;
        }

        $names = implode(', ', array_map(static fn (PackageInterface $package) => $package->getName(), $outdated));
        $this->io->writeError("<info>composer-patches-local: reinstalling packages with changed patches: $names</info>");
        $manager = $this->composer->getInstallationManager();
        $manager->execute($localRepo, array_map(static fn ($package) => new UninstallOperation($package), $outdated), $devMode);
        $manager->execute($localRepo, array_map(static fn ($package) => new InstallOperation($package), $outdated), $devMode);
    }

    /**
     * Returns the loaded patches of a package.
     * For the packages of composer-patches, it returns [].
     *
     * @return list<Patch>
     */
    private function patchesFor(string $name): array
    {
        if (in_array($name, ComposerPatches::OWN_PACKAGES, true)) {
            return [];
        }
        $collection = ComposerPatches::loadedPatches(ComposerPatches::find($this->composer));

        /** @var list<Patch> $patches */
        $patches = $collection?->getPatchesForPackage($name) ?? [];

        return $patches;
    }

    /**
     * Returns a hash of the patch contents and depths, or null when there are no patches.
     *
     * @param list<Patch> $patches
     */
    private function patchHash(array $patches): ?string
    {
        if ($patches === []) {
            return null;
        }
        $parts = array_map(static fn (Patch $patch) => [$patch->sha256 ?? null, $patch->depth ?? null], $patches);

        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }
}
