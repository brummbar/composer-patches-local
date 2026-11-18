<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal;

use Composer\Composer;
use cweagans\Composer\Patch;
use cweagans\Composer\PatchCollection;

/**
 * Writes patches.lock.json from all resolvers. Of patches with the same content, it keeps only the first one.
 */
final class Relock
{
    public function __construct(
        private readonly Composer $composer,
        private readonly SkippedPackages $plugin,
    ) {
    }

    /**
     * @param list<string> $skippedPackages
     *   Packages whose local patches are not ready on disk.
     * @param bool $full
     *   True after the last relock of a run. Then the lock gets a fingerprint.
     */
    public function run(array $skippedPackages, bool $full = false): void
    {
        $patches = ComposerPatches::find($this->composer);
        $this->plugin->setSkippedPackages($skippedPackages);
        try {
            /** @var PatchCollection $resolved */
            $resolved = $patches->resolvePatches();
        } finally {
            $this->plugin->setSkippedPackages([]);
        }

        // Download first: the collection compares sha256 values to drop patches with the same content.
        $collection = new PatchCollection();
        /** @var list<string> $packageNames */
        $packageNames = $resolved->getPatchedPackages();
        foreach ($packageNames as $package) {
            /** @var list<Patch> $packagePatches */
            $packagePatches = $resolved->getPatchesForPackage($package);
            foreach ($packagePatches as $patch) {
                $patches->download($patch);
                $patches->guessDepth($patch);
                $collection->addPatch($patch);
            }
        }
        $patches->getLocker()->setLockData($collection);
        if ($full) {
            Fingerprint::write($this->composer);
        }
    }
}
