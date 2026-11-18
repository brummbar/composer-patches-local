<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal\Tests\Integration;

use Brummbar\ComposerPatchesLocal\Tests\Support\ComposerProject;
use Brummbar\ComposerPatchesLocal\Tests\Support\ComposerTestCase;
use Brummbar\ComposerPatchesLocal\Tests\Support\FileSystem;
use Brummbar\ComposerPatchesLocal\Tests\Support\LayeredFixtures as Layers;

/**
 * @phpstan-import-type PatchesLock from ComposerProject
 *
 * Tests the rule check and the command patches-local-check.
 */
final class RuleCheckTest extends ComposerTestCase
{
    private const COPY = 'patches/dependencies/acme/module-one/acme/lib/lib-line-two.patch';

    public function testTheCommandSucceedsOnACleanProject(): void
    {
        $project = $this->installedProject();

        $result = $project->composer(['patches-local-check']);

        $this->assertComposerSucceeded($result);
        $this->assertOutputContains('All patches follow the rules', $result);
    }

    public function testTheCommandFailsWhenPatchesLockJsonDoesNotExist(): void
    {
        $project = $this->installedProject();
        unlink($project->projectPath('patches.lock.json'));

        $result = $project->composer(['patches-local-check']);

        $this->assertComposerFailed($result);
        $this->assertOutputContains('patches.lock.json does not exist', $result);
    }

    public function testARemoteUrlInTheDefinitionsOfTheRootStopsUpdateInstallAndTheCommand(): void
    {
        $project = $this->installedProject();
        $project->setRoot(Layers::site([], ['patches' => ['acme/lib' => ['Remote fix' => Layers::REMOTE_PATCH_URL]]]));

        foreach ([['update'], ['install'], ['patches-local-check']] as $arguments) {
            $result = $project->composer($arguments);

            $this->assertComposerFailed($result);
            $this->assertOutputContains('acme/lib, "Remote fix" (http://patches/lib-line-two.patch): the patch is a URL', $result);
        }
    }

    public function testAPathOutsidePatchesInThePatchesFileOfTheRootStopsTheCommand(): void
    {
        $project = $this->installedProject();
        $project->setRoot(Layers::site([
            'patches.json' => json_encode(['patches' => ['acme/lib' => [['url' => 'patches/../other/x.patch', 'description' => 'Escapes']]]], JSON_THROW_ON_ERROR),
        ]));

        $result = $project->composer(['patches-local-check']);

        $this->assertComposerFailed($result);
        $this->assertOutputContains('acme/lib, "Escapes" (patches/../other/x.patch): the patch is not a file inside patches/', $result);
    }

    public function testALockUrlOutsidePatchesStopsInstallAndTheCommand(): void
    {
        $project = $this->installedProject();
        $this->editLock($project, static function (array $lock): array {
            $lock['patches']['acme/lib'][0]['url'] = 'vendor/acme/module-one/patches/lib-line-two.patch';
            return $lock;
        });

        foreach ([['install'], ['patches-local-check']] as $arguments) {
            $result = $project->composer($arguments);

            $this->assertComposerFailed($result);
            $this->assertOutputContains('acme/lib, "Change line two" (vendor/acme/module-one/patches/lib-line-two.patch): the patch is not a file inside patches/', $result);
        }
    }

    public function testALockEntryWithAnExtraProgramStopsInstallAndTheCommand(): void
    {
        $project = $this->installedProject();
        $this->editLock($project, static function (array $lock): array {
            $lock['patches']['acme/lib'][0]['extra']['freeform'] = ['executable' => 'touch', 'args' => 'marker.txt'];
            return $lock;
        });

        foreach ([['install'], ['patches-local-check']] as $arguments) {
            $result = $project->composer($arguments);

            $this->assertComposerFailed($result);
            $this->assertOutputContains('extra keys that a dependency patch must not have: freeform', $result);
        }
    }

    public function testAMissingCopyStopsInstallAndTheCommand(): void
    {
        $project = $this->installedProject();
        unlink($project->projectPath(self::COPY));

        foreach ([['install'], ['patches-local-check']] as $arguments) {
            $result = $project->composer($arguments);

            $this->assertComposerFailed($result);
            $this->assertOutputContains('acme/lib, "Change line two" (' . self::COPY . '): the file does not exist', $result);
        }
    }

    public function testACopyWithChangedContentStopsInstallAndTheCommand(): void
    {
        $project = $this->installedProject();
        FileSystem::write($project->projectPath(self::COPY), Layers::lineTwoPatch('someone else'));

        foreach ([['install'], ['patches-local-check']] as $arguments) {
            $result = $project->composer($arguments);

            $this->assertComposerFailed($result);
            $this->assertOutputContains('acme/lib, "Change line two" (' . self::COPY . '): the file content does not match the sha256', $result);
        }
    }

    public function testALockEntryWithoutSha256StopsTheCommand(): void
    {
        $project = $this->installedProject();
        $this->editLock($project, static function (array $lock): array {
            unset($lock['patches']['acme/lib'][0]['sha256']);
            return $lock;
        });

        $result = $project->composer(['patches-local-check']);

        $this->assertComposerFailed($result);
        $this->assertOutputContains('patches.lock.json has no sha256 for the file', $result);
    }

    public function testAnUpdateRepairsAChangedCopy(): void
    {
        $project = $this->installedProject();
        FileSystem::write($project->projectPath(self::COPY), 'broken');

        $this->assertComposerSucceeded($project->composer(['update']));

        $this->assertProjectFileSame(Layers::lineTwoPatch('module-one'), $project, self::COPY);
        $this->assertComposerSucceeded($project->composer(['patches-local-check']));
    }

    public function testAFreshInstallStopsAtTheEndWhenTheLockBreaksTheRules(): void
    {
        $project = $this->installedProject();
        FileSystem::write($project->projectPath('other/lib.patch'), Layers::lineTwoPatch('module-one'));
        $this->editLock($project, static function (array $lock): array {
            $lock['patches']['acme/lib'][0]['url'] = 'other/lib.patch';
            return $lock;
        });
        $checkout = $this->track($project->checkout(['composer.lock', 'patches.lock.json', 'patches', 'other']));

        $result = $checkout->composer(['install']);

        $this->assertComposerFailed($result);
        $this->assertOutputContains('(other/lib.patch): the patch is not a file inside patches/', $result);
    }

    private function installedProject(): ComposerProject
    {
        $project = $this->layeredProject(Layers::moduleOneWithLocalPatch());
        $this->assertComposerSucceeded($project->composer(['install']));

        return $project;
    }

    /**
     * @param callable(PatchesLock): array<mixed> $change
     */
    private function editLock(ComposerProject $project, callable $change): void
    {
        $lock = $change($project->readPatchesLock());
        FileSystem::write($project->projectPath('patches.lock.json'), json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
