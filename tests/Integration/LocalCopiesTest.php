<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal\Tests\Integration;

use Brummbar\ComposerPatchesLocal\Tests\Support\ComposerProject;
use Brummbar\ComposerPatchesLocal\Tests\Support\ComposerTestCase;
use Brummbar\ComposerPatchesLocal\Tests\Support\FileSystem;
use Brummbar\ComposerPatchesLocal\Tests\Support\FixturePackage;
use Brummbar\ComposerPatchesLocal\Tests\Support\LayeredFixtures as Layers;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests the copies of dependency patches in patches/dependencies/.
 */
final class LocalCopiesTest extends ComposerTestCase
{
    private const FOLDER = 'patches/dependencies/acme/module-one/acme/lib';

    private const LINE_ONE_LOCAL = 'patches/lib-line-one.patch';

    public function testALocalAndARemotePatchAreCopiedAndASecondUpdateChangesNothing(): void
    {
        $project = $this->projectWithLocalAndRemotePatch();

        $this->assertComposerSucceeded($project->composer(['install']));

        $this->assertProjectFileSame(
            "line one patched by module-one\nline two\nline three patched by b\n",
            $project,
            'vendor/acme/lib/lib.txt',
        );
        $entries = $project->readPatchesLock()['patches']['acme/lib'];
        $this->assertCount(2, $entries);
        $this->assertSame(self::FOLDER . '/lib-line-one.patch', $entries[0]['url']);
        $this->assertSame(['provenance' => 'dependency:acme/module-one', 'source' => self::LINE_ONE_LOCAL], $entries[0]['extra']);
        $this->assertSame(self::FOLDER . '/fix.patch', $entries[1]['url']);
        $this->assertSame(['provenance' => 'dependency:acme/module-one', 'source' => 'http://patches/b/fix.patch?download=1#top'], $entries[1]['extra']);
        $this->assertProjectFileSame(Layers::lineOnePatch('module-one'), $project, self::FOLDER . '/lib-line-one.patch');
        $this->assertProjectFileSame((string) file_get_contents(__DIR__ . '/../fixtures/remote-patches/b/fix.patch'), $project, self::FOLDER . '/fix.patch');
        $lock = $project->readFile('patches.lock.json');
        $copies = $project->tree('patches/dependencies');

        $this->assertComposerSucceeded($project->composer(['update']));

        $this->assertProjectFileSame($lock, $project, 'patches.lock.json');
        $this->assertSame($copies, $project->tree('patches/dependencies'));
    }

    public function testTwoDifferentPatchesWithTheSameFileNameGetAHashPrefix(): void
    {
        $project = $this->layeredProject(Layers::moduleOne('1.0.0', ['acme/lib' => [
            'Change line one' => 'http://patches/a/fix.patch',
            'Change line three' => 'http://patches/b/fix.patch',
        ]]));

        $this->assertComposerSucceeded($project->composer(['install']));

        $prefixA = substr(hash('sha256', (string) file_get_contents(__DIR__ . '/../fixtures/remote-patches/a/fix.patch')), 0, 8);
        $prefixB = substr(hash('sha256', (string) file_get_contents(__DIR__ . '/../fixtures/remote-patches/b/fix.patch')), 0, 8);
        $this->assertSame(
            ["$prefixA-fix.patch", "$prefixB-fix.patch"],
            array_keys($project->tree(self::FOLDER)),
        );
        $urls = array_column($project->readPatchesLock()['patches']['acme/lib'], 'url');
        $this->assertSame([self::FOLDER . "/$prefixA-fix.patch", self::FOLDER . "/$prefixB-fix.patch"], $urls);
        $this->assertProjectFileSame(
            "line one patched by a\nline two\nline three patched by b\n",
            $project,
            'vendor/acme/lib/lib.txt',
        );
    }

    public function testTwoPatchNamesThatDifferOnlyInCaseGetAHashPrefix(): void
    {
        $project = $this->layeredProject(Layers::moduleOne(
            '1.0.0',
            ['acme/lib' => [
                'Change line one' => 'patches/Fix.patch',
                'Change line three' => 'http://patches/b/fix.patch',
            ]],
            ['patches/Fix.patch' => Layers::lineOnePatch('module-one')],
        ));

        $this->assertComposerSucceeded($project->composer(['install']));

        $prefixUpper = substr(hash('sha256', Layers::lineOnePatch('module-one')), 0, 8);
        $prefixLower = substr(hash('sha256', (string) file_get_contents(__DIR__ . '/../fixtures/remote-patches/b/fix.patch')), 0, 8);
        $upper = "$prefixUpper-Fix.patch";
        $lower = "$prefixLower-fix.patch";
        $names = [$upper, $lower];
        sort($names);
        $this->assertSame($names, array_keys($project->tree(self::FOLDER)));
        $urls = array_column($project->readPatchesLock()['patches']['acme/lib'], 'url');
        $this->assertSame([self::FOLDER . "/$upper", self::FOLDER . "/$lower"], $urls);
        $this->assertProjectFileSame(
            "line one patched by module-one\nline two\nline three patched by b\n",
            $project,
            'vendor/acme/lib/lib.txt',
        );
    }

    public function testACopyAndItsEmptyFoldersAreDeletedWhenTheDependencyDropsThePatch(): void
    {
        $project = $this->layeredProject($this->moduleOneWithLocalAndRemotePatch());
        $this->assertComposerSucceeded($project->composer(['install']));
        FileSystem::write($project->projectPath('patches/own.txt'), 'mine');
        FileSystem::write($project->projectPath('patches/dependencies/acme/old/keep-me.txt'), 'unused copy');
        $project->addPackage(Layers::moduleOne('1.1.0'));

        $this->assertComposerSucceeded($project->composer(['update']));

        $this->assertProjectFileSame(Layers::LIB_FILE, $project, 'vendor/acme/lib/lib.txt');
        $this->assertSame([], $project->tree('patches/dependencies'));
        $this->assertDirectoryDoesNotExist($project->projectPath('patches/dependencies'));
        $this->assertDirectoryExists($project->projectPath('patches'));
        $this->assertProjectFileSame('mine', $project, 'patches/own.txt');
    }

    public function testAFileOutsideTheCopyFolderStaysWhenTheCleanUpRuns(): void
    {
        $project = $this->layeredProject(Layers::moduleOneWithLocalPatch());
        $this->assertComposerSucceeded($project->composer(['install']));
        FileSystem::write($project->projectPath('other/keep.txt'), 'mine');
        FileSystem::write($project->projectPath('patches/keep.patch'), 'mine');
        $project->addPackage(Layers::moduleOne('1.1.0'));

        $this->assertComposerSucceeded($project->composer(['update']));

        $this->assertProjectFileSame('mine', $project, 'other/keep.txt');
        $this->assertProjectFileSame('mine', $project, 'patches/keep.patch');
    }

    public function testATargetNameThatLeavesTheCopyFolderStopsTheRun(): void
    {
        $project = $this->layeredProject(Layers::moduleOne(
            '1.0.0',
            [
                'acme/lib' => ['Change line two' => Layers::LOCAL_PATCH_PATH],
                '../../..' => ['Bad' => 'patches/outside.patch'],
            ],
            [
                Layers::LOCAL_PATCH_PATH => Layers::lineTwoPatch('module-one'),
                'patches/outside.patch' => Layers::lineTwoPatch('evil'),
            ],
        ));

        $result = $project->composer(['install']);

        $this->assertFalse($project->fileExists('patches/outside.patch'), 'A copy must not leave its folder.');
        $this->assertComposerFailed($result);
        $this->assertOutputContains('acme/module-one', $result);
        $this->assertOutputContains('../../..', $result);
    }

    public function testAPatchForAPackageThatIsNotInTheProjectIsSkipped(): void
    {
        $project = $this->layeredProject(Layers::moduleOne(
            '1.0.0',
            [
                'acme/lib' => ['Change line two' => Layers::LOCAL_PATCH_PATH],
                'acme/absent' => ['Change something' => 'patches/absent.patch'],
            ],
            [
                Layers::LOCAL_PATCH_PATH => Layers::lineTwoPatch('module-one'),
                'patches/absent.patch' => Layers::lineTwoPatch('absent'),
            ],
        ));

        $result = $project->composer(['install']);

        $this->assertComposerSucceeded($result);
        $this->assertSame(
            [],
            $project->tree('patches/dependencies/acme/module-one/acme/absent'),
            'The plugin must not copy a patch for a package that is not in the project.',
        );
        $this->assertArrayNotHasKey(
            'acme/absent',
            $project->readPatchesLock()['patches'],
            'The patches lock must not list a package that is not in the project.',
        );
        $this->assertProjectFileSame(
            "line one\nline two patched by module-one\nline three\n",
            $project,
            'vendor/acme/lib/lib.txt',
        );
    }

    #[DataProvider('providePatchPathsOutsideThePackage')]
    public function testAPatchPathOutsideThePackageStopsTheRun(string $declaredPath): void
    {
        $project = $this->createProject();
        $path = str_replace('{project}', $project->projectPath(), $declaredPath);
        Layers::addLayers($project, Layers::moduleOne('1.0.0', ['acme/lib' => ['Read a secret' => $path]]));
        $project->setRoot(Layers::site())->withPlugin();
        FileSystem::write($project->projectPath('secret.txt'), "SECRET\n");

        $result = $project->composer(['install']);

        $this->assertSame(
            [],
            $project->tree('patches/dependencies'),
            'A dependency must not copy a file from outside its package folder.',
        );
        $this->assertComposerFailed($result);
        $this->assertOutputContains('acme/module-one', $result);
        $this->assertOutputContains($path, $result);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function providePatchPathsOutsideThePackage(): array
    {
        return [
            'relative path' => ['../../../secret.txt'],
            'absolute path' => ['{project}/secret.txt'],
            'file URL' => ['file://{project}/secret.txt'],
        ];
    }

    public function testAnAllowedSchemeLetsADependencyPatchUseAFileUrl(): void
    {
        $project = $this->createProject();
        $url = 'file://' . $project->projectPath('line-two.patch');
        Layers::addLayers($project, Layers::moduleOne('1.0.0', ['acme/lib' => ['Change line two' => $url]]));
        $project->setRoot(Layers::site(
            ['line-two.patch' => Layers::lineTwoPatch('module-one')],
            ['composer-patches-local' => ['allowed-schemes' => ['file']]],
        ))->withPlugin();

        $this->assertComposerSucceeded($project->composer(['install']));

        $this->assertProjectFileSame(
            "line one\nline two patched by module-one\nline three\n",
            $project,
            'vendor/acme/lib/lib.txt',
        );
        $this->assertSame(['line-two.patch'], array_keys($project->tree(self::FOLDER)));
    }

    public function testPatchesRelockWritesALockThatPointsAtCopies(): void
    {
        $project = $this->projectWithLocalAndRemotePatch();
        $this->assertComposerSucceeded($project->composer(['install']));
        $lock = $project->readPatchesLock();
        FileSystem::remove($project->projectPath('patches/dependencies'));

        $this->assertComposerSucceeded($project->composer(['patches-relock']));

        // After the relock, only the fingerprint key is missing.
        unset($lock['composer-patches-local']);
        $this->assertSame($lock, $project->readPatchesLock());
        $this->assertSame(
            [self::FOLDER . '/lib-line-one.patch', self::FOLDER . '/fix.patch'],
            array_column($project->readPatchesLock()['patches']['acme/lib'], 'url'),
        );
        $this->assertCount(2, $project->tree('patches/dependencies'));
    }

    public function testACleanCheckoutInstallsCopiesWhenRemoteUrlsAreUnreachable(): void
    {
        $project = $this->projectWithLocalAndRemotePatch();
        $this->assertComposerSucceeded($project->composer(['install']));
        $lock = $project->readFile('patches.lock.json');
        $checkout = $this->track($project->checkout(['composer.lock', 'patches.lock.json', 'patches']));
        $offline = $this->unreachableNetwork();

        // Control step: with this setting, a remote download fails.
        $control = $project->composer(['patches-relock'], $offline);
        $this->assertComposerFailed($control);

        $result = $checkout->composer(['install'], $offline);

        $this->assertComposerSucceeded($result);
        $this->assertProjectFileSame(
            "line one patched by module-one\nline two\nline three patched by b\n",
            $checkout,
            'vendor/acme/lib/lib.txt',
        );
        $this->assertProjectFileSame($lock, $checkout, 'patches.lock.json');
        $this->assertSame($project->tree('patches'), $checkout->tree('patches'));
    }

    public function testARootPatchWithTheSameContentAsADependencyPatchWinsAndNoCopyStays(): void
    {
        $project = $this->createProject();
        Layers::addLayers($project, Layers::moduleOneWithLocalPatch());
        $site = Layers::site([
            'patches.json' => json_encode(['patches' => ['acme/lib' => ['Root patch' => 'patches/lib.patch']]], JSON_THROW_ON_ERROR),
            'patches/lib.patch' => Layers::lineTwoPatch('module-one'),
        ]);
        $project->setRoot($site)->withPlugin();

        $this->assertComposerSucceeded($project->composer(['install']));
        $this->assertRootPatchWins($project);

        $this->assertComposerSucceeded($project->composer(['update']));
        $this->assertRootPatchWins($project);
    }

    private function assertRootPatchWins(ComposerProject $project): void
    {
        $entries = $project->readPatchesLock()['patches']['acme/lib'];
        $this->assertCount(1, $entries);
        $this->assertSame('patches/lib.patch', $entries[0]['url']);
        $this->assertSame('patches-file:patches.json', $entries[0]['extra']['provenance']);
        $this->assertSame([], $project->tree('patches/dependencies'));
        $this->assertProjectFileSame("line one\nline two patched by module-one\nline three\n", $project, 'vendor/acme/lib/lib.txt');
    }

    private function moduleOneWithLocalAndRemotePatch(): FixturePackage
    {
        return Layers::moduleOne(
            '1.0.0',
            ['acme/lib' => [
                'Change line one' => self::LINE_ONE_LOCAL,
                'Change line three' => 'http://patches/b/fix.patch?download=1#top',
            ]],
            [self::LINE_ONE_LOCAL => Layers::lineOnePatch('module-one')],
        );
    }

    private function projectWithLocalAndRemotePatch(): ComposerProject
    {
        return $this->layeredProject($this->moduleOneWithLocalAndRemotePatch());
    }
}
