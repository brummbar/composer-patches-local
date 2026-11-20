<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal\Tests\Integration;

use Brummbar\ComposerPatchesLocal\Tests\Support\ComposerProject;
use Brummbar\ComposerPatchesLocal\Tests\Support\ComposerResult;
use Brummbar\ComposerPatchesLocal\Tests\Support\ComposerTestCase;
use Brummbar\ComposerPatchesLocal\Tests\Support\FileSystem;
use Brummbar\ComposerPatchesLocal\Tests\Support\LayeredFixtures as Layers;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests the copies of dependency patches in patches/dependencies/.
 */
final class LocalCopiesTest extends ComposerTestCase
{
    private const FOLDER = 'patches/dependencies/acme/module-one/acme/lib';

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
        $this->assertSame(['provenance' => 'dependency:acme/module-one', 'source' => Layers::LINE_ONE_PATCH_PATH], $entries[0]['extra']);
        $this->assertSame(self::FOLDER . '/fix.patch', $entries[1]['url']);
        $this->assertSame(['provenance' => 'dependency:acme/module-one', 'source' => Layers::REMOTE_FIX_URL], $entries[1]['extra']);
        $this->assertProjectFileSame(Layers::lineOnePatch('module-one'), $project, self::FOLDER . '/lib-line-one.patch');
        $this->assertProjectFileSame((string) file_get_contents(__DIR__ . '/../fixtures/remote-patches/b/fix.patch'), $project, self::FOLDER . '/fix.patch');
        $lock = $project->readFile('patches.lock.json');
        $copies = $project->tree('patches/dependencies');

        $this->assertComposerSucceeded($project->composer(['update']));

        $this->assertProjectFileSame($lock, $project, 'patches.lock.json');
        $this->assertSame($copies, $project->tree('patches/dependencies'));
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
        $upper = "$prefixUpper-fix.patch";
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
        $project = $this->layeredProject(Layers::moduleOneWithLocalAndRemotePatch());
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

    #[DataProvider('skippedTargets')]
    public function testADependencyPatchForATargetThatComposerPatchesIgnoresIsSkipped(string $target): void
    {
        $project = $this->layeredProject(Layers::moduleOne(
            '1.0.0',
            [
                'acme/lib' => ['Change line two' => Layers::LOCAL_PATCH_PATH],
                $target => ['Skipped' => 'patches/skipped.patch'],
            ],
            [
                Layers::LOCAL_PATCH_PATH => Layers::lineTwoPatch('module-one'),
                'patches/skipped.patch' => Layers::lineTwoPatch('skipped'),
            ],
        ));

        $result = $project->composer(['update']);

        $this->assertComposerSucceeded($result);
        $this->assertSame(
            ['acme/lib'],
            array_keys($project->readPatchesLock()['patches']),
            'The patches lock must list only the real target.',
        );
        $this->assertSame(
            ['dependencies/acme/module-one/acme/lib/lib-line-two.patch'],
            array_keys($project->tree('patches')),
            'The plugin must copy only the patch of the real target. A copy must not leave its folder.',
        );
        $this->assertProjectFileSame(
            "line one\nline two patched by module-one\nline three\n",
            $project,
            'vendor/acme/lib/lib.txt',
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function skippedTargets(): array
    {
        return [
            'not a package name' => ['../../..'],
            'package not in the project' => ['acme/absent'],
            'package of composer-patches' => ['cweagans/composer-configurable-plugin'],
            'capital letters' => ['Acme/Lib'],
        ];
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

    public function testAnAllowedSchemeWithANewlineAtTheEndStopsTheRun(): void
    {
        $project = $this->layeredProject(
            Layers::moduleOneWithLocalPatch(),
            Layers::site([], ['composer-patches-local' => ['allowed-schemes' => ["https\n"]]]),
        );

        $result = $project->composer(['install']);

        $this->assertComposerFailed($result);
        $this->assertOutputContains('must be a list of URL schemes', $result);
    }

    public function testARemoteDependencyPatchWithAWrongSha256StopsTheRun(): void
    {
        $project = $this->layeredProject(Layers::moduleOne('1.0.0', ['acme/lib' => [[
            'description' => 'Change line two',
            'url' => Layers::REMOTE_PATCH_URL,
            'sha256' => hash('sha256', 'other text'),
        ]]]));

        $this->assertComposerFailed($project->composer(['update']));

        $this->assertSame([], $project->tree(self::FOLDER));
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

    public function testAnUpdateListsTheCopiesThatItWritesAndDeletes(): void
    {
        $project = $this->layeredProject(Layers::moduleOneWithLocalPatch());
        $first = $project->composer(['install']);
        $this->assertComposerSucceeded($first);
        $this->assertOutputContains('patches/dependencies/acme/module-one/acme/lib/lib-line-two.patch', $first);
        $project->addPackage(Layers::moduleOne('1.1.0', [
            'acme/lib' => [
                'Change line one' => Layers::LINE_ONE_PATCH_PATH,
                'Change line three' => 'http://user:secret@patches/b/fix.patch',
            ],
        ], [Layers::LINE_ONE_PATCH_PATH => Layers::lineOnePatch('module-one')]));

        $update = $project->composer(['update']);

        $this->assertComposerSucceeded($update);
        $this->assertOutputContains('composer-patches-local: copied these patches from dependencies:', $update);
        $this->assertOutputContains('  - ' . self::FOLDER . '/fix.patch (from http://user:***@patches/b/fix.patch)', $update);
        $this->assertOutputContains('  - ' . self::FOLDER . '/lib-line-one.patch (from patches/lib-line-one.patch in acme/module-one)', $update);
        $this->assertOutputContains('composer-patches-local: deleted these patch copies, because no patch uses them now:', $update);
        $this->assertOutputContains('  - ' . self::FOLDER . '/lib-line-two.patch', $update);
        $this->assertStringNotContainsString('secret', $update->output);

        $again = $project->composer(['update']);

        $this->assertComposerSucceeded($again);
        $this->assertNoCopyReport($again);
    }

    public function testARootPatchWithTheSameContentAsADependencyPatchWinsAndNoCopyStays(): void
    {
        $site = Layers::site([
            'patches.json' => json_encode(['patches' => ['acme/lib' => ['Root patch' => 'patches/lib.patch']]], JSON_THROW_ON_ERROR),
            'patches/lib.patch' => Layers::lineTwoPatch('module-one'),
        ]);
        $project = $this->layeredProject(Layers::moduleOneWithLocalPatch(), $site);

        $install = $project->composer(['install']);
        $this->assertComposerSucceeded($install);
        $this->assertNoCopyReport($install);
        $this->assertRootPatchWins($project);

        $update = $project->composer(['update']);
        $this->assertComposerSucceeded($update);
        $this->assertNoCopyReport($update);
        $this->assertRootPatchWins($project);
    }

    private function assertNoCopyReport(ComposerResult $result): void
    {
        $this->assertStringNotContainsString('copied these patches from dependencies', $result->output);
        $this->assertStringNotContainsString('deleted these patch copies', $result->output);
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

    private function projectWithLocalAndRemotePatch(): ComposerProject
    {
        return $this->layeredProject(Layers::moduleOneWithLocalAndRemotePatch());
    }
}
