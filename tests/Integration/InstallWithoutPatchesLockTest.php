<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal\Tests\Integration;

use Brummbar\ComposerPatchesLocal\Tests\Support\ComposerProject;
use Brummbar\ComposerPatchesLocal\Tests\Support\ComposerTestCase;
use Brummbar\ComposerPatchesLocal\Tests\Support\FileSystem;
use Brummbar\ComposerPatchesLocal\Tests\Support\LayeredFixtures as Layers;

/**
 * Tests composer install when patches.lock.json does not exist.
 */
final class InstallWithoutPatchesLockTest extends ComposerTestCase
{
    private const FOLDER = 'patches/dependencies/acme/module-one/acme/lib';

    private const PATCHED = "line one patched by module-one\nline two\nline three patched by b\n";

    public function testAnInstallFromComposerLockWithoutVendorAppliesTheDependencyPatches(): void
    {
        $project = $this->installedProject();
        $checkout = $this->track($project->checkout(['composer.lock']));

        $this->assertComposerSucceeded($checkout->composer(['install']));

        $this->assertProjectFileSame(self::PATCHED, $checkout, 'vendor/acme/lib/lib.txt');
        $this->assertSame(
            [self::FOLDER . '/lib-line-one.patch', self::FOLDER . '/fix.patch'],
            array_column($checkout->readPatchesLock()['patches']['acme/lib'], 'url'),
        );
        $this->assertSame($project->tree('patches'), $checkout->tree('patches'));
        $this->assertComposerSucceeded($checkout->composer(['patches-local-check']));
    }

    public function testAnInstallWithVendorAndWithoutPatchesLockWritesTheLockWithCopies(): void
    {
        $project = $this->installedProject();
        $lock = $project->readFile('patches.lock.json');
        unlink($project->projectPath('patches.lock.json'));
        FileSystem::remove($project->projectPath('patches'));

        $result = $project->composer(['install']);

        $this->assertComposerSucceeded($result);
        $this->assertOutputContains('Nothing to install, update or remove', $result);
        $this->assertProjectFileSame($lock, $project, 'patches.lock.json');
        $this->assertCount(2, $project->tree('patches/dependencies'));
        $this->assertProjectFileSame(self::PATCHED, $project, 'vendor/acme/lib/lib.txt');
    }

    public function testReinstallWithoutAPatchesLockKeepsThePackageAndItsPatches(): void
    {
        $project = $this->layeredProject(Layers::moduleOneWithLocalPatch());
        $this->assertComposerSucceeded($project->composer(['install']));
        unlink($project->projectPath('patches.lock.json'));

        $result = $project->composer(['reinstall', 'acme/module-one']);

        $this->assertComposerSucceeded($result);
        $this->assertDirectoryExists($project->projectPath('vendor/acme/module-one'));
        $this->assertFileExists($project->projectPath('patches.lock.json'));
        $this->assertProjectFileSame(
            "line one\nline two patched by module-one\nline three\n",
            $project,
            'vendor/acme/lib/lib.txt',
        );
    }

    private function installedProject(): ComposerProject
    {
        $project = $this->layeredProject(Layers::moduleOneWithLocalAndRemotePatch());
        $this->assertComposerSucceeded($project->composer(['install']));
        $this->assertProjectFileSame(self::PATCHED, $project, 'vendor/acme/lib/lib.txt');

        return $project;
    }
}
