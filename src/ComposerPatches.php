<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal;

use Composer\Composer;
use cweagans\Composer\PatchCollection;
use cweagans\Composer\Plugin\Patches;

/**
 * Gives access to the composer-patches plugin, its settings and its lock file.
 */
final class ComposerPatches
{
    private const DEPENDENCIES_RESOLVER = '\\cweagans\\Composer\\Resolver\\Dependencies';

    public static function find(Composer $composer): Patches
    {
        foreach ($composer->getPluginManager()->getPlugins() as $plugin) {
            if ($plugin instanceof Patches) {
                return $plugin;
            }
        }
        throw new \RuntimeException('composer-patches-local needs the plugin cweagans/composer-patches, but it is not active.');
    }

    /**
     * Returns the folder of the root project.
     */
    public static function projectDirectory(Patches $patches): string
    {
        return dirname($patches->getLockFile()->getPath());
    }

    /**
     * Stops with an error when the root project does not turn off the core dependency resolver.
     */
    public static function assertConfigured(Patches $patches): void
    {
        $disabled = $patches->getConfig('disable-resolvers');
        if (is_array($disabled) && in_array(self::DEPENDENCIES_RESOLVER, $disabled, true)) {
            return;
        }
        $json = json_encode(
            ['extra' => ['composer-patches' => ['disable-resolvers' => [self::DEPENDENCIES_RESOLVER]]]],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        throw new \RuntimeException(
            "composer-patches-local needs a setting in the composer.json of the root project.\n"
            . "Add this value to \"disable-resolvers\" in extra.composer-patches:\n" . $json
        );
    }

    /**
     * Returns the patches that composer-patches loaded last, or null before the first load.
     */
    /**
     * Returns the content of patches.lock.json.
     *
     * @return array<string|int, mixed>
     */
    public static function readLock(Patches $patches): array
    {
        $data = $patches->getLockFile()->read();

        return is_array($data) ? $data : [];
    }

    public static function loadedPatches(Patches $patches): ?PatchCollection
    {
        try {
            return $patches->getPatchCollection();
        } catch (\Error) {
            // The property is not set until composer-patches loads patches.
            return null;
        }
    }
}
