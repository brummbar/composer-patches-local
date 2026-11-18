<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal\Tests\Integration;

use Brummbar\ComposerPatchesLocal\Tests\Support\ComposerProject;
use Brummbar\ComposerPatchesLocal\Tests\Support\ComposerTestCase;
use Brummbar\ComposerPatchesLocal\Tests\Support\FileSystem;
use Brummbar\ComposerPatchesLocal\Tests\Support\LayeredFixtures as Layers;

/**
 * Tests the fingerprint in patches.lock.json and the installs that depend on it.
 */
final class FingerprintTest extends ComposerTestCase
{
    private const KEY = 'composer-patches-local';

    private const LINE_THREE_LOCAL = 'patches/lib-line-three.patch';

    public function testAnUpdateWritesTheFingerprintAndASecondUpdateGivesTheSameFile(): void
    {
        $project = $this->projectWithLocalPatch();

        $this->assertComposerSucceeded($project->composer(['install']));

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $project->readPatchesLock()[self::KEY] ?? '');
        $lock = $project->readFile('patches.lock.json');

        $this->assertComposerSucceeded($project->composer(['update']));

        $this->assertProjectFileSame($lock, $project, 'patches.lock.json');
    }

    public function testPatchesRelockRemovesTheFingerprintAndTheNextInstallWritesItAgain(): void
    {
        $project = $this->projectWithLocalPatch();
        $this->assertComposerSucceeded($project->composer(['update']));
        $lock = $project->readFile('patches.lock.json');

        $this->assertComposerSucceeded($project->composer(['patches-relock']));
        $this->assertArrayNotHasKey(self::KEY, $project->readPatchesLock());

        $this->assertComposerSucceeded($project->composer(['install']));

        $this->assertProjectFileSame($lock, $project, 'patches.lock.json');
    }

    public function testAnInstallRebuildsAStaleLockOfACleanCheckout(): void
    {
        $project = $this->projectWithLocalPatch();
        $this->assertComposerSucceeded($project->composer(['install']));
        $oldLock = $project->readFile('patches.lock.json');
        $oldCopies = $project->tree('patches');
        $project->addPackage(Layers::moduleOne(
            '1.1.0',
            ['acme/lib' => ['Change line one' => 'patches/lib-line-one.patch', 'Change line three' => self::LINE_THREE_LOCAL]],
            ['patches/lib-line-one.patch' => Layers::lineOnePatch('module-one'), self::LINE_THREE_LOCAL => Layers::lineThreePatch('module-one')],
        ));
        $this->assertComposerSucceeded($project->composer(['update']));
        $newLock = $project->readFile('patches.lock.json');
        $this->assertNotSame($oldLock, $newLock);

        $checkout = $this->track($project->checkout(['composer.lock']));
        FileSystem::write($checkout->projectPath('patches.lock.json'), $oldLock);
        foreach ($oldCopies as $path => $content) {
            FileSystem::write($checkout->projectPath('patches/' . $path), $content);
        }

        $this->assertComposerSucceeded($checkout->composer(['install']));

        $this->assertProjectFileSame("line one patched by module-one\nline two\nline three patched by module-one\n", $checkout, 'vendor/acme/lib/lib.txt');
        $this->assertProjectFileSame($newLock, $checkout, 'patches.lock.json');
    }

    public function testAnInstallAppliesANewRootPatchWithoutARelockAndWithoutNetwork(): void
    {
        $project = $this->createProject();
        Layers::addLayers($project, Layers::moduleOne());
        $project->setRoot(Layers::site())->withPlugin();
        $this->assertComposerSucceeded($project->composer(['install']));
        $oldLock = $project->readPatchesLock();

        $project->setRoot(Layers::site(
            ['patches/root.patch' => Layers::lineTwoPatch('root')],
            ['patches' => ['acme/lib' => ['Root patch' => 'patches/root.patch']]],
        ));

        $this->assertComposerSucceeded($project->composer(['install'], $this->unreachableNetwork()));

        $this->assertProjectFileSame("line one\nline two patched by root\nline three\n", $project, 'vendor/acme/lib/lib.txt');
        $lock = $project->readPatchesLock();
        $this->assertNotSame($oldLock[self::KEY] ?? null, $lock[self::KEY] ?? null);
        $this->assertSame('patches/root.patch', $lock['patches']['acme/lib'][0]['url']);
    }

    public function testAChangeOfPackageDepthsChangesTheFingerprint(): void
    {
        $project = $this->createProject();
        Layers::addLayers($project, Layers::moduleOneWithLocalPatch());
        $project->setRoot(Layers::site())->withPlugin();
        $this->assertComposerSucceeded($project->composer(['install']));
        $oldFingerprint = $project->readPatchesLock()[self::KEY] ?? null;

        $project->setRoot(Layers::site([], ['composer-patches' => ['package-depths' => ['acme/lib' => 1]]]));
        $this->assertComposerSucceeded($project->composer(['install']));

        $this->assertNotSame(
            $oldFingerprint,
            $project->readPatchesLock()[self::KEY] ?? null,
            'A change of package-depths must change the fingerprint.',
        );
    }

    public function testInstallWithDownloadOnlyChangesNothing(): void
    {
        $project = $this->createProject();
        Layers::addLayers($project, Layers::moduleOne());
        $project->setRoot(Layers::site())->withPlugin();
        $this->assertComposerSucceeded($project->composer(['install']));

        $project->setRoot(Layers::site(
            ['patches/root.patch' => Layers::lineTwoPatch('root')],
            ['patches' => ['acme/lib' => ['Root patch' => 'patches/root.patch']]],
        ));
        $vendorFile = $project->readFile('vendor/acme/lib/lib.txt');
        $lock = $project->readFile('patches.lock.json');

        $this->assertComposerSucceeded($project->composer(['install', '--download-only']));

        $this->assertProjectFileSame($vendorFile, $project, 'vendor/acme/lib/lib.txt');
        $this->assertProjectFileSame($lock, $project, 'patches.lock.json');
    }

    public function testUpdateLockRebuildsTheLockAndAppliesANewRootPatch(): void
    {
        $project = $this->createProject();
        Layers::addLayers($project, Layers::moduleOne());
        $project->setRoot(Layers::site())->withPlugin();
        $this->assertComposerSucceeded($project->composer(['install']));
        $oldLock = $project->readPatchesLock();

        $project->setRoot(Layers::site(
            ['patches/root.patch' => Layers::lineTwoPatch('root')],
            ['patches' => ['acme/lib' => ['Root patch' => 'patches/root.patch']]],
        ));

        $this->assertComposerSucceeded($project->composer(['update', '--lock']));

        $lock = $project->readPatchesLock();
        $this->assertNotSame($oldLock[self::KEY] ?? null, $lock[self::KEY] ?? null);
        $this->assertSame('patches/root.patch', $lock['patches']['acme/lib'][0]['url']);
        $this->assertProjectFileSame("line one\nline two patched by root\nline three\n", $project, 'vendor/acme/lib/lib.txt');
    }

    private function projectWithLocalPatch(): ComposerProject
    {
        $project = $this->createProject();
        Layers::addLayers($project, Layers::moduleOne(
            '1.0.0',
            ['acme/lib' => ['Change line one' => 'patches/lib-line-one.patch']],
            ['patches/lib-line-one.patch' => Layers::lineOnePatch('module-one')],
        ));
        $project->setRoot(Layers::site())->withPlugin();

        return $project;
    }
}
