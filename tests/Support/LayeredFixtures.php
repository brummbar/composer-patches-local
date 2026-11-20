<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal\Tests\Support;

/**
 * Fixture packages for the layered case: site > module-two > module-one > lib.
 *
 * The lib has one text file. Each patch changes one line of it.
 */
final class LayeredFixtures
{
    public const LIB_FILE = "line one\nline two\nline three\n";

    public const LOCAL_PATCH_PATH = 'patches/lib-line-two.patch';

    public const REMOTE_PATCH_URL = 'http://patches/lib-line-two.patch';

    public const LINE_ONE_PATCH_PATH = 'patches/lib-line-one.patch';

    public const REMOTE_FIX_URL = 'http://patches/b/fix.patch?download=1#top';

    public static function lib(): FixturePackage
    {
        return new FixturePackage('acme/lib', '1.0.0', ['lib.txt' => self::LIB_FILE]);
    }

    /**
     * @param array<string, array<mixed>> $patches
     *   Patch definitions for extra.patches, keyed by target package.
     * @param array<string, string> $files
     */
    public static function moduleOne(string $version = '1.0.0', array $patches = [], array $files = []): FixturePackage
    {
        return new FixturePackage(
            'acme/module-one',
            $version,
            $files,
            ['acme/lib' => '^1.0'],
            $patches ? ['patches' => $patches] : [],
        );
    }

    /**
     * Module-one with a local patch file and a definition that points to it.
     */
    public static function moduleOneWithLocalPatch(string $version = '1.0.0'): FixturePackage
    {
        return self::moduleOne(
            $version,
            ['acme/lib' => ['Change line two' => self::LOCAL_PATCH_PATH]],
            [self::LOCAL_PATCH_PATH => self::lineTwoPatch('module-one')],
        );
    }

    /**
     * Module-one with a local patch for line one and a remote patch for line three.
     */
    public static function moduleOneWithLocalAndRemotePatch(): FixturePackage
    {
        return self::moduleOne(
            '1.0.0',
            ['acme/lib' => [
                'Change line one' => self::LINE_ONE_PATCH_PATH,
                'Change line three' => self::REMOTE_FIX_URL,
            ]],
            [self::LINE_ONE_PATCH_PATH => self::lineOnePatch('module-one')],
        );
    }

    public static function moduleTwo(): FixturePackage
    {
        return new FixturePackage('acme/module-two', '1.0.0', [], ['acme/module-one' => '^1.0']);
    }

    /**
     * The root project of the layered case.
     *
     * @param array<string, string> $files
     * @param array<string, mixed> $extra
     * @param array<string, string> $require
     *   Requirements in addition to module-two.
     */
    public static function site(array $files = [], array $extra = [], array $require = []): FixturePackage
    {
        return new FixturePackage('acme/site', '1.0.0', $files, ['acme/module-two' => '^1.0'] + $require, $extra, 'project');
    }

    /**
     * Builds a patch for lib.txt that changes line two.
     */
    public static function lineTwoPatch(string $author): string
    {
        return <<<PATCH
        --- a/lib.txt
        +++ b/lib.txt
        @@ -1,3 +1,3 @@
         line one
        -line two
        +line two patched by $author
         line three

        PATCH;
    }

    /**
     * Builds a patch that changes line one. Its context is line two.
     */
    public static function lineOnePatch(string $author): string
    {
        return <<<PATCH
        --- a/lib.txt
        +++ b/lib.txt
        @@ -1,2 +1,2 @@
        -line one
        +line one patched by $author
         line two

        PATCH;
    }

    /**
     * Builds a patch that changes line three. Its context is line two.
     */
    public static function lineThreePatch(string $author): string
    {
        return <<<PATCH
        --- a/lib.txt
        +++ b/lib.txt
        @@ -2,2 +2,2 @@
         line two
        -line three
        +line three patched by $author

        PATCH;
    }

    /**
     * Adds lib, module-one and module-two to a project.
     */
    public static function addLayers(ComposerProject $project, FixturePackage $moduleOne): void
    {
        $project->addPackage(self::lib())
            ->addPackage($moduleOne)
            ->addPackage(self::moduleTwo());
    }
}
