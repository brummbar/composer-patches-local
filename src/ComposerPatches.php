<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal;

use Composer\Composer;
use Composer\Plugin\PluginInterface;
use cweagans\Composer\PatchCollection;
use cweagans\Composer\Plugin\Patches;

/**
 * Gives access to the composer-patches plugin, its settings and its lock file.
 *
 * When Composer updates composer-patches during a run, it loads the new version under a different class name.
 * That class has the same methods as Patches, but it does not extend Patches.
 * Because of this, the PHP type of the plugin object is PluginInterface in all methods of this class.
 * The phpdoc type is Patches. It tells PHPStan which methods the object has.
 */
final class ComposerPatches
{
    private const DEPENDENCIES_RESOLVER = '\\cweagans\\Composer\\Resolver\\Dependencies';

    /**
     * Returns the plugin object of composer-patches.
     *
     * @return Patches
     */
    public static function find(Composer $composer): PluginInterface
    {
        foreach ($composer->getPluginManager()->getPlugins() as $plugin) {
            if ($plugin instanceof Patches || str_starts_with($plugin::class, Patches::class . '_composer_tmp')) {
                /** @var Patches $plugin */
                return $plugin;
            }
        }
        throw new \RuntimeException('composer-patches-local needs the plugin cweagans/composer-patches, but it is not active.');
    }

    /**
     * Returns the folder of the root project.
     *
     * @param Patches $patches
     */
    public static function projectDirectory(PluginInterface $patches): string
    {
        return dirname($patches->getLockFile()->getPath());
    }

    /**
     * Stops with an error when the root project does not turn off the core dependency resolver.
     *
     * @param Patches $patches
     */
    public static function assertConfigured(PluginInterface $patches): void
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
     * Returns the content of patches.lock.json.
     *
     * @param Patches $patches
     * @return array<string|int, mixed>
     */
    public static function readLock(PluginInterface $patches): array
    {
        $data = $patches->getLockFile()->read();

        return is_array($data) ? $data : [];
    }

    /**
     * Returns the patches that composer-patches loaded last, or null before the first load.
     *
     * @param Patches $patches
     */
    public static function loadedPatches(PluginInterface $patches): ?PatchCollection
    {
        try {
            return $patches->getPatchCollection();
        } catch (\Error) {
            // The property is not set until composer-patches loads patches.
            return null;
        }
    }
}
