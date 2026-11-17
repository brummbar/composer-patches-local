<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal\Tests\Baseline;

use Brummbar\ComposerPatchesLocal\Tests\Support\ComposerTestCase;
use Brummbar\ComposerPatchesLocal\Tests\Support\FixturePackage;
use Brummbar\ComposerPatchesLocal\Tests\Support\LayeredFixtures as Layers;

/**
 * Pins the behaviour of plain cweagans/composer-patches v2 (without this plugin).
 *
 * These tests describe the upstream problem. They do not test this plugin.
 */
final class UpstreamComposerPatchesBehaviourTest extends ComposerTestCase
{
    public function testUpstreamAppliesLocalPatchInTheDeclaringPackageOwnRootProject(): void
    {
        $project = $this->createProject();
        $project->addPackage(Layers::lib());
        $moduleOne = Layers::moduleOneWithLocalPatch();
        $project->setRoot(new FixturePackage(
            'acme/module-one', '1.0.0', $moduleOne->files, $moduleOne->require, $moduleOne->extra, 'project',
        ));

        $this->assertComposerSucceeded($project->composer(['install']));

        $this->assertProjectFileSame(
            "line one\nline two patched by module-one\nline three\n",
            $project,
            'vendor/acme/lib/lib.txt',
        );
    }

    public function testUpstreamFailsToInstallWhenADependencyDeclaresALocalPatch(): void
    {
        $project = $this->createProject();
        Layers::addLayers($project, Layers::moduleOneWithLocalPatch());
        $project->setRoot(Layers::site());

        $result = $project->composer(['install']);

        $this->assertComposerFailed($result);
        $this->assertOutputContains(
            'The "patches/lib-line-two.patch" file could not be downloaded: Failed to open stream: No such file or directory',
            $result,
        );
    }

    public function testUpstreamAppliesTheRootProjectFileWhenARootFileHasTheSamePathAsADependencyPatch(): void
    {
        $project = $this->createProject();
        Layers::addLayers($project, Layers::moduleOneWithLocalPatch());
        $project->setRoot(Layers::site([Layers::LOCAL_PATCH_PATH => Layers::lineTwoPatch('site')]));

        $this->assertComposerSucceeded($project->composer(['install']));

        $this->assertProjectFileSame(
            "line one\nline two patched by site\nline three\n",
            $project,
            'vendor/acme/lib/lib.txt',
        );
    }

    public function testUpstreamAppliesARemotePatchThatADependencyDeclaresTwoLevelsUp(): void
    {
        $project = $this->createProject();
        $moduleOne = Layers::moduleOne('1.0.0', ['acme/lib' => ['Change line two' => Layers::REMOTE_PATCH_URL]]);
        Layers::addLayers($project, $moduleOne);
        $project->setRoot(Layers::site());

        $this->assertComposerSucceeded($project->composer(['install']));

        $this->assertProjectFileSame(
            "line one\nline two patched by remote\nline three\n",
            $project,
            'vendor/acme/lib/lib.txt',
        );
    }

    public function testUpstreamDoesNotRefreshAnExistingPatchesLockWhenADependencyAddsAPatch(): void
    {
        $project = $this->createProject();
        Layers::addLayers($project, Layers::moduleOne());
        $project->setRoot(Layers::site());
        $this->assertComposerSucceeded($project->composer(['install']));
        $lockBefore = $project->readFile('patches.lock.json');

        $project->addPackage(Layers::moduleOne('1.1.0', ['acme/lib' => ['Change line two' => Layers::REMOTE_PATCH_URL]]));
        $this->assertComposerSucceeded($project->composer(['update', 'acme/module-one']));

        $this->assertStringContainsString('"version": "1.1.0"', $project->readFile('composer.lock'));
        $this->assertSame($lockBefore, $project->readFile('patches.lock.json'));
        $this->assertProjectFileSame(Layers::LIB_FILE, $project, 'vendor/acme/lib/lib.txt');
    }
}
