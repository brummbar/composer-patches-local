<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal\Tests\Integration;

use Brummbar\ComposerPatchesLocal\Tests\Support\ComposerProject;
use Brummbar\ComposerPatchesLocal\Tests\Support\ComposerTestCase;
use Brummbar\ComposerPatchesLocal\Tests\Support\FileSystem;
use Brummbar\ComposerPatchesLocal\Tests\Support\FixturePackage;
use Brummbar\ComposerPatchesLocal\Tests\Support\LayeredFixtures as Layers;

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
        $project = $this->createProject();
        Layers::addLayers($project, Layers::moduleOne('1.0.0', ['acme/lib' => [
            'Change line one' => 'http://patches/a/fix.patch',
            'Change line three' => 'http://patches/b/fix.patch',
        ]]));
        $project->setRoot(Layers::site())->withPlugin();

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

    public function testACopyAndItsEmptyFoldersAreDeletedWhenTheDependencyDropsThePatch(): void
    {
        $project = $this->createProject();
        Layers::addLayers($project, $this->moduleOneWithLocalAndRemotePatch());
        $project->setRoot(Layers::site())->withPlugin();
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
        $project = $this->createProject();
        Layers::addLayers($project, Layers::moduleOneWithLocalPatch());
        $project->setRoot(Layers::site())->withPlugin();
        $this->assertComposerSucceeded($project->composer(['install']));
        FileSystem::write($project->projectPath('other/keep.txt'), 'mine');
        FileSystem::write($project->projectPath('patches/keep.patch'), 'mine');
        $project->addPackage(Layers::moduleOne('1.1.0'));

        $this->assertComposerSucceeded($project->composer(['update']));

        $this->assertProjectFileSame('mine', $project, 'other/keep.txt');
        $this->assertProjectFileSame('mine', $project, 'patches/keep.patch');
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
        $project = $this->createProject();
        Layers::addLayers($project, $this->moduleOneWithLocalAndRemotePatch());
        $project->setRoot(Layers::site())->withPlugin();

        return $project;
    }

    /**
     * Returns environment variables that send HTTP traffic to a closed local port.
     *
     * @return array<string, string>
     */
    private function unreachableNetwork(): array
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($server);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
        fclose($server);
        $proxy = "http://127.0.0.1:$port";

        return ['http_proxy' => $proxy, 'HTTP_PROXY' => $proxy, 'no_proxy' => '', 'NO_PROXY' => ''];
    }
}
