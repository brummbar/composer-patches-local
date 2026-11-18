<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal;

use Composer\Composer;
use Composer\Package\AliasPackage;

/**
 * A hash of the inputs of patches.lock.json. The plugin writes it into the lock after a full relock.
 */
final class Fingerprint
{
    public const KEY = 'composer-patches-local';

    /**
     * Returns the hash of the patch inputs of the root project and of the locked packages.
     */
    public static function compute(Composer $composer): string
    {
        $patches = ComposerPatches::find($composer);
        $file = $patches->getConfig('patches-file');
        $packages = [];
        $lockedNames = [];
        $locker = $composer->getLocker();
        if ($locker->isLocked()) {
            foreach ($locker->getLockedRepository()->getPackages() as $package) {
                $extra = $package->getExtra();
                if ($package instanceof AliasPackage || empty($extra['patches'])) {
                    continue;
                }
                $packages[$package->getName()] = [
                    'name' => $package->getName(),
                    'version' => $package->getVersion(),
                    'source' => $package->getSourceReference(),
                    'dist' => $package->getDistReference(),
                    'patches' => $extra['patches'],
                ];
            }
            foreach ($locker->getLockedRepository(true)->getPackages() as $package) {
                $lockedNames[$package->getName()] = true;
            }
        }
        ksort($packages);
        $lockedNames = array_keys($lockedNames);
        sort($lockedNames);

        $inputs = [
            'root-patches' => $composer->getPackage()->getExtra()['patches'] ?? null,
            'patches-file' => is_string($file) && is_file($file) ? (string) file_get_contents($file) : null,
            'ignore-dependency-patches' => $patches->getConfig('ignore-dependency-patches'),
            'packages' => array_values($packages),
            'locked-packages' => $lockedNames,
        ];

        return hash('sha256', json_encode($inputs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * Tells if patches.lock.json has a fingerprint that equals the one of the current inputs.
     */
    public static function isCurrent(Composer $composer): bool
    {
        $lockFile = ComposerPatches::find($composer)->getLockFile();
        if (!$lockFile->exists()) {
            return false;
        }
        try {
            $stored = ComposerPatches::readLock(ComposerPatches::find($composer))[self::KEY] ?? null;
        } catch (\Throwable) {
            return false;
        }

        return is_string($stored) && hash_equals($stored, self::compute($composer));
    }

    public static function write(Composer $composer): void
    {
        $lockFile = ComposerPatches::find($composer)->getLockFile();
        $data = ComposerPatches::readLock(ComposerPatches::find($composer));
        $data[self::KEY] = self::compute($composer);
        ksort($data);
        $lockFile->write($data);
    }
}
