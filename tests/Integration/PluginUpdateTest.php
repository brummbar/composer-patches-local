<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal\Tests\Integration;

use Brummbar\ComposerPatchesLocal\Tests\Support\ComposerProject;
use Brummbar\ComposerPatchesLocal\Tests\Support\ComposerTestCase;
use Brummbar\ComposerPatchesLocal\Tests\Support\FixturePackage;
use Brummbar\ComposerPatchesLocal\Tests\Support\LayeredFixtures as Layers;

/**
 * Tests runs in which Composer updates a plugin, with real Composer.
 */
final class PluginUpdateTest extends ComposerTestCase
{
    public function testAnUpdateOfComposerPatchesInTheRunDoesNotStopTheRun(): void
    {
        $project = $this->layeredProject(Layers::moduleOneWithLocalPatch());
        $this->assertComposerSucceeded($project->composer(['install']));
        $project->releaseUpstreamUpdate('cweagans/composer-patches', '2.0.1')
            ->addPackage(new FixturePackage('acme/lib', '1.1.0', ['lib.txt' => "line one\nline two\nline three\nline four\n"]));

        $result = $project->composer(['update']);

        $this->assertComposerSucceeded($result);
        $this->assertOutputContains('Upgrading cweagans/composer-patches', $result);
        $this->assertProjectFileSame(
            "line one\nline two patched by module-one\nline three\nline four\n",
            $project,
            'vendor/acme/lib/lib.txt',
        );
    }

    private function layeredProject(FixturePackage $moduleOne): ComposerProject
    {
        $project = $this->createProject();
        Layers::addLayers($project, $moduleOne);
        $project->setRoot(Layers::site())->withPlugin();

        return $project;
    }
}
