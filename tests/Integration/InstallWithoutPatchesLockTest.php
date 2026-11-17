<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal\Tests\Integration;

use Brummbar\ComposerPatchesLocal\Tests\Support\ComposerProject;
use Brummbar\ComposerPatchesLocal\Tests\Support\ComposerTestCase;
use Brummbar\ComposerPatchesLocal\Tests\Support\FixturePackage;
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
        $this->removeTree($project->projectPath('patches'));

        $result = $project->composer(['install']);

        $this->assertComposerSucceeded($result);
        $this->assertOutputContains('Nothing to install, update or remove', $result);
        $this->assertProjectFileSame($lock, $project, 'patches.lock.json');
        $this->assertCount(2, $project->tree('patches/dependencies'));
        $this->assertProjectFileSame(self::PATCHED, $project, 'vendor/acme/lib/lib.txt');
    }

    public function testAnInstallWithPatchesLockDoesNotRelock(): void
    {
        $project = $this->installedProject();
        $lock = $project->readFile('patches.lock.json');
        $copies = $project->tree('patches');

        $this->assertComposerSucceeded($project->composer(['install']));

        $this->assertProjectFileSame($lock, $project, 'patches.lock.json');
        $this->assertSame($copies, $project->tree('patches'));
    }

    private function installedProject(): ComposerProject
    {
        $project = $this->createProject();
        Layers::addLayers($project, Layers::moduleOne(
            '1.0.0',
            ['acme/lib' => [
                'Change line one' => 'patches/lib-line-one.patch',
                'Change line three' => 'http://patches/b/fix.patch',
            ]],
            ['patches/lib-line-one.patch' => Layers::lineOnePatch('module-one')],
        ));
        $project->setRoot(Layers::site())->withPlugin();
        $this->assertComposerSucceeded($project->composer(['install']));
        $this->assertProjectFileSame(self::PATCHED, $project, 'vendor/acme/lib/lib.txt');

        return $project;
    }

    private function removeTree(string $path): void
    {
        \Brummbar\ComposerPatchesLocal\Tests\Support\FileSystem::remove($path);
    }
}
