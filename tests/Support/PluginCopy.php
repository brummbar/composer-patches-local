<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal\Tests\Support;

/**
 * A clean copy of this plugin: composer.json and src/ only. The harness makes the copy once for each test run and deletes it at the end of the run.
 */
final class PluginCopy
{
    public const NAME = 'brummbar/composer-patches-local';

    public const VERSION = '1.0.0';

    private static ?string $path = null;

    public static function path(): string
    {
        if (self::$path !== null) {
            return self::$path;
        }
        $root = dirname(__DIR__, 2);
        $path = sys_get_temp_dir() . '/cpl-plugin-' . bin2hex(random_bytes(6));
        mkdir($path, 0777, true);
        copy($root . '/composer.json', $path . '/composer.json');
        FileSystem::copyDirectory($root . '/src', $path . '/src');
        register_shutdown_function(static fn () => FileSystem::remove($path));
        return self::$path = $path;
    }
}
