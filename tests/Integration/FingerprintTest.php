<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal\Tests\Integration;

use Brummbar\ComposerPatchesLocal\Tests\Support\ComposerProject;
use Brummbar\ComposerPatchesLocal\Tests\Support\ComposerResult;
use Brummbar\ComposerPatchesLocal\Tests\Support\ComposerTestCase;
use Brummbar\ComposerPatchesLocal\Tests\Support\FileSystem;
use Brummbar\ComposerPatchesLocal\Tests\Support\FixturePackage;
use Brummbar\ComposerPatchesLocal\Tests\Support\LayeredFixtures as Layers;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests the fingerprint in patches.lock.json and the installs that depend on it.
 */
final class FingerprintTest extends ComposerTestCase
{
    private const KEY = 'composer-patches-local';

    private const NO_DEV_ERROR = [
        'composer-patches-local: patches.lock.json is missing or out of date, and this install runs without dev packages.',
        'The plugin does not rebuild patches.lock.json in this mode.',
        'Run composer update in development, and commit composer.lock, patches.lock.json and patches/ together.',
    ];

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
            ['acme/lib' => ['Change line one' => Layers::LINE_ONE_PATCH_PATH, 'Change line three' => self::LINE_THREE_LOCAL]],
            [Layers::LINE_ONE_PATCH_PATH => Layers::lineOnePatch('module-one'), self::LINE_THREE_LOCAL => Layers::lineThreePatch('module-one')],
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
        $project = $this->layeredProject(Layers::moduleOne());
        $this->assertComposerSucceeded($project->composer(['install']));
        $oldLock = $project->readPatchesLock();

        $project->setRoot($this->siteWithRootPatch());

        $this->assertComposerSucceeded($project->composer(['install'], $this->unreachableNetwork()));

        $this->assertProjectFileSame("line one\nline two patched by root\nline three\n", $project, 'vendor/acme/lib/lib.txt');
        $lock = $project->readPatchesLock();
        $this->assertNotSame($oldLock[self::KEY] ?? null, $lock[self::KEY] ?? null);
        $this->assertSame('patches/root.patch', $lock['patches']['acme/lib'][0]['url']);
    }

    public function testAChangeOfPackageDepthsChangesTheFingerprint(): void
    {
        $project = $this->layeredProject(Layers::moduleOneWithLocalPatch());
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
        $project = $this->layeredProject(Layers::moduleOne());
        $this->assertComposerSucceeded($project->composer(['install']));

        $project->setRoot($this->siteWithRootPatch());
        $vendorFile = $project->readFile('vendor/acme/lib/lib.txt');
        $lock = $project->readFile('patches.lock.json');

        $this->assertComposerSucceeded($project->composer(['install', '--download-only']));

        $this->assertProjectFileSame($vendorFile, $project, 'vendor/acme/lib/lib.txt');
        $this->assertProjectFileSame($lock, $project, 'patches.lock.json');
    }

    public function testUpdateLockRebuildsTheLockAndAppliesANewRootPatch(): void
    {
        $project = $this->layeredProject(Layers::moduleOne());
        $this->assertComposerSucceeded($project->composer(['install']));
        $oldLock = $project->readPatchesLock();

        $project->setRoot($this->siteWithRootPatch());

        $this->assertComposerSucceeded($project->composer(['update', '--lock']));

        $lock = $project->readPatchesLock();
        $this->assertNotSame($oldLock[self::KEY] ?? null, $lock[self::KEY] ?? null);
        $this->assertSame('patches/root.patch', $lock['patches']['acme/lib'][0]['url']);
        $this->assertProjectFileSame("line one\nline two patched by root\nline three\n", $project, 'vendor/acme/lib/lib.txt');
    }

    public function testAnUnrelatedPackageDoesNotChangeTheLock(): void
    {
        $project = $this->layeredProject(Layers::moduleOneWithLocalPatch());
        $this->assertComposerSucceeded($project->composer(['install']));
        $lock = $project->readFile('patches.lock.json');

        $project->addPackage(new FixturePackage('acme/unrelated', '1.0.0', ['unrelated.txt' => "unrelated\n"]));
        $project->setRoot(Layers::site([], [], ['acme/unrelated' => '^1.0']));
        $this->assertComposerSucceeded($project->composer(['update']));

        $this->assertProjectFileSame($lock, $project, 'patches.lock.json');
    }

    public function testATargetThatArrivesLaterMakesAnInstallRebuildTheLock(): void
    {
        $project = $this->layeredProject(Layers::moduleOne(
            '1.0.0',
            ['acme/extra' => ['Change line two' => Layers::LOCAL_PATCH_PATH]],
            [Layers::LOCAL_PATCH_PATH => Layers::lineTwoPatch('module-one')],
        ));
        $project->addPackage(new FixturePackage('acme/extra', '1.0.0', ['lib.txt' => Layers::LIB_FILE]));
        $this->assertComposerSucceeded($project->composer(['install']));
        $oldLock = $project->readFile('patches.lock.json');
        $this->assertArrayNotHasKey('acme/extra', $project->readPatchesLock()['patches']);

        $project->setRoot(Layers::site([], [], ['acme/extra' => '^1.0']));
        $this->assertComposerSucceeded($project->composer(['update']));
        $newLock = $project->readFile('patches.lock.json');
        $this->assertNotSame($oldLock, $newLock);

        $checkout = $this->track($project->checkout(['composer.lock']));
        FileSystem::write($checkout->projectPath('patches.lock.json'), $oldLock);

        $this->assertComposerSucceeded($checkout->composer(['install']));

        $this->assertProjectFileSame("line one\nline two patched by module-one\nline three\n", $checkout, 'vendor/acme/extra/lib.txt');
        $this->assertProjectFileSame($newLock, $checkout, 'patches.lock.json');
        $this->assertArrayHasKey('acme/extra', $checkout->readPatchesLock()['patches']);
        $this->assertNotSame([], $checkout->tree('patches/dependencies/acme/module-one/acme/extra'));
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $scripts
     */
    #[DataProvider('currentLockInstalls')]
    public function testAnInstallWithACurrentLockDoesNotRelock(array $arguments, array $scripts): void
    {
        $project = $this->projectWithRemotePatch();
        $lock = $project->readFile('patches.lock.json');
        $copies = $project->tree('patches');
        $project->setScripts($scripts);

        $result = $project->composer($arguments, $this->unreachableNetwork());

        $this->assertComposerSucceeded($result);
        $this->assertProjectFileSame($lock, $project, 'patches.lock.json');
        $this->assertSame($copies, $project->tree('patches'));
    }

    /**
     * @return array<string, array{list<string>, array<string, string>}>
     */
    public static function currentLockInstalls(): array
    {
        $script = ['post-install-cmd' => '@post-update-cmd'];

        return [
            'with dev packages' => [['install'], []],
            'without dev packages' => [['install', '--no-dev'], []],
            'with dev packages and a script that calls post-update-cmd' => [['install'], $script],
            'without dev packages and a script that calls post-update-cmd' => [['install', '--no-dev'], $script],
        ];
    }

    public function testInstallWithoutDevReinstallsAPackageWhoseRootPatchChanged(): void
    {
        $project = $this->layeredProject(Layers::moduleOne());
        $this->assertComposerSucceeded($project->composer(['install']));
        FileSystem::copyDirectory($project->projectPath('vendor'), $project->projectPath('old-vendor'));
        $project->setRoot($this->siteWithRootPatch());
        $this->assertComposerSucceeded($project->composer(['update', '--lock']));
        $lock = $project->readFile('patches.lock.json');
        FileSystem::remove($project->projectPath('vendor'));
        FileSystem::copyDirectory($project->projectPath('old-vendor'), $project->projectPath('vendor'));

        $result = $project->composer(['install', '--no-dev'], $this->unreachableNetwork());

        $this->assertComposerSucceeded($result);
        $this->assertProjectFileSame("line one\nline two patched by root\nline three\n", $project, 'vendor/acme/lib/lib.txt');
        $this->assertProjectFileSame($lock, $project, 'patches.lock.json');
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $environment
     */
    #[DataProvider('noDevModes')]
    public function testInstallWithoutDevStopsWhenTheFingerprintIsDifferent(array $arguments, array $environment): void
    {
        $project = $this->projectWithRemotePatch();
        $this->writeFingerprint($project, 'wrong');
        $lock = $project->readFile('patches.lock.json');
        $copies = $project->tree('patches');

        $result = $project->composer($arguments, $environment + $this->unreachableNetwork());

        $this->assertComposerFailed($result);
        $this->assertNoDevError($result);
        $this->assertProjectFileSame($lock, $project, 'patches.lock.json');
        $this->assertSame($copies, $project->tree('patches'));
    }

    /**
     * @return array<string, array{list<string>, array<string, string>}>
     */
    public static function noDevModes(): array
    {
        return [
            'option' => [['install', '--no-dev'], []],
            'environment variable' => [['install'], ['COMPOSER_NO_DEV' => '1']],
        ];
    }

    public function testFreshInstallWithoutDevStopsWhenTheFingerprintIsDifferent(): void
    {
        $project = $this->projectWithRemotePatch();
        $this->writeFingerprint($project, 'wrong');
        $lock = $project->readFile('patches.lock.json');
        $copies = $project->tree('patches');
        $checkout = $this->track($project->checkout(['composer.lock', 'patches.lock.json', 'patches']));

        $result = $checkout->composer(['install', '--no-dev'], $this->unreachableNetwork());

        $this->assertComposerFailed($result);
        $this->assertNoDevError($result);
        $this->assertProjectFileSame($lock, $checkout, 'patches.lock.json');
        $this->assertSame($copies, $checkout->tree('patches'));
    }

    private function assertNoDevError(ComposerResult $result): void
    {
        foreach (self::NO_DEV_ERROR as $line) {
            $this->assertOutputContains($line, $result);
        }
    }

    private function writeFingerprint(ComposerProject $project, string $fingerprint): void
    {
        $lock = $project->readPatchesLock();
        $lock[self::KEY] = $fingerprint;
        $project->writePatchesLock($lock);
    }

    private function projectWithRemotePatch(): ComposerProject
    {
        $project = $this->layeredProject(Layers::moduleOneWithLocalAndRemotePatch());
        $this->assertComposerSucceeded($project->composer(['install']));

        return $project;
    }

    private function projectWithLocalPatch(): ComposerProject
    {
        $project = $this->layeredProject(Layers::moduleOne(
            '1.0.0',
            ['acme/lib' => ['Change line one' => Layers::LINE_ONE_PATCH_PATH]],
            [Layers::LINE_ONE_PATCH_PATH => Layers::lineOnePatch('module-one')],
        ));

        return $project;
    }

    private function siteWithRootPatch(): FixturePackage
    {
        return Layers::site(
            ['patches/root.patch' => Layers::lineTwoPatch('root')],
            ['patches' => ['acme/lib' => ['Root patch' => 'patches/root.patch']]],
        );
    }
}
