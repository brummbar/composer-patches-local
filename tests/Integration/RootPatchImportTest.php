<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal\Tests\Integration;

use Brummbar\ComposerPatchesLocal\Tests\Support\ComposerProject;
use Brummbar\ComposerPatchesLocal\Tests\Support\ComposerTestCase;
use Brummbar\ComposerPatchesLocal\Tests\Support\FileSystem;
use Brummbar\ComposerPatchesLocal\Tests\Support\LayeredFixtures as Layers;

/**
 * Tests the command patches-local-import, with real Composer.
 */
final class RootPatchImportTest extends ComposerTestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/remote-patches';

    private const PATCHES_JSON = <<<'JSON'
    {
      "patches": {
        "acme/module-one": [
          {
            "description": "Change the module file",
            "url": "http://patches/module-one.patch",
            "depth": 1,
            "extra": {}
          }
        ]
      }
    }

    JSON;

    public function testTheFirstUseWithRemotePatchesInBothDefinitionFiles(): void
    {
        $project = $this->layeredProject(
            Layers::moduleOne('1.0.0', [], ['module.txt' => "module one\n"]),
            Layers::site(
                [
                    'patches/lib-line-one.patch' => Layers::lineOnePatch('site'),
                    'patches.json' => self::PATCHES_JSON,
                ],
                ['patches' => ['acme/lib' => [
                    'Change line one' => 'patches/lib-line-one.patch',
                    'Change line three' => 'http://patches/b/fix.patch?download=1#top',
                ]]],
            ),
        );

        $result = $project->composer(['install']);
        $this->assertComposerFailed($result);
        $this->assertOutputContains('acme/lib, "Change line three" (http://patches/b/fix.patch?download=1#top): the patch is a URL', $result);
        $this->assertOutputContains('Run composer patches-local-import to download the remote patches of the root project into the patches/ folder.', $result);

        $result = $project->composer(['patches-local-import']);
        $this->assertComposerSucceeded($result);
        $this->assertOutputContains(
            "composer-patches-local: downloaded these remote patches into the patches/ folder:\n"
            . "  - patches/acme/lib/fix.patch (from http://patches/b/fix.patch?download=1#top)\n"
            . "  - patches/acme/module-one/module-one.patch (from http://patches/module-one.patch)\n"
            . 'Run composer install to update patches.lock.json.',
            $result,
        );
        $this->assertProjectFileSame((string) file_get_contents(self::FIXTURES . '/b/fix.patch'), $project, 'patches/acme/lib/fix.patch');
        $this->assertProjectFileSame((string) file_get_contents(self::FIXTURES . '/module-one.patch'), $project, 'patches/acme/module-one/module-one.patch');
        $this->assertSame(
            ['Change line one' => 'patches/lib-line-one.patch', 'Change line three' => 'patches/acme/lib/fix.patch'],
            $this->libPatches($project),
        );
        $this->assertProjectFileSame(
            str_replace('http://patches/module-one.patch', 'patches/acme/module-one/module-one.patch', self::PATCHES_JSON),
            $project,
            'patches.json',
        );

        $result = $project->composer(['install'], $this->unreachableNetwork());
        $this->assertComposerSucceeded($result);
        $this->assertStringNotContainsString('lock file is not up to date', $result->output);
        $locked = $project->readPatchesLock()['patches'];
        $libUrls = array_column($locked['acme/lib'], 'url');
        sort($libUrls);
        $this->assertSame(['patches/acme/lib/fix.patch', 'patches/lib-line-one.patch'], $libUrls);
        $this->assertSame(['patches/acme/module-one/module-one.patch'], array_column($locked['acme/module-one'], 'url'));
        $this->assertProjectFileSame("line one patched by site\nline two\nline three patched by b\n", $project, 'vendor/acme/lib/lib.txt');
        $this->assertProjectFileSame("module one patched by the site\n", $project, 'vendor/acme/module-one/module.txt');
        $this->assertComposerSucceeded($project->composer(['patches-local-check']));
    }

    public function testAListOfUrlStringsIsImported(): void
    {
        $project = $this->layeredProject(Layers::moduleOne(), Layers::site([], ['patches' => ['acme/lib' => ['http://patches/lib-line-two.patch']]]));
        $this->assertComposerFailed($project->composer(['install']));

        $this->assertComposerSucceeded($project->composer(['patches-local-import']));

        $this->assertSame(['patches/acme/lib/lib-line-two.patch'], $this->libPatches($project));
    }

    public function testAFailedDownloadChangesNoFile(): void
    {
        $project = $this->layeredProject(Layers::moduleOne(), Layers::site([], ['patches' => ['acme/lib' => [
            'Change line two' => 'http://patches/lib-line-two.patch',
            'Missing' => 'http://patches/missing.patch',
        ]]]));
        $this->assertComposerFailed($project->composer(['install']));
        $before = $project->readFile('composer.json');

        $this->assertComposerFailed($project->composer(['patches-local-import']));

        $this->assertSame([], $project->tree('patches/acme/lib'));
        $this->assertProjectFileSame($before, $project, 'composer.json');
    }

    public function testAnImportDoesNotOverwriteAFileWithOtherContent(): void
    {
        $project = $this->layeredProject(Layers::moduleOne(), Layers::site(
            ['patches/acme/lib/lib-line-two.patch' => 'old content'],
            ['patches' => ['acme/lib' => ['Change line two' => 'http://patches/lib-line-two.patch']]],
        ));
        $this->assertComposerFailed($project->composer(['install']));
        $content = (string) file_get_contents(self::FIXTURES . '/lib-line-two.patch');
        $new = 'patches/acme/lib/' . substr(hash('sha256', $content), 0, 8) . '-lib-line-two.patch';

        $this->assertComposerSucceeded($project->composer(['patches-local-import']));

        $this->assertProjectFileSame('old content', $project, 'patches/acme/lib/lib-line-two.patch');
        $this->assertProjectFileSame($content, $project, $new);
        $this->assertSame(['Change line two' => $new], $this->libPatches($project));
    }

    /**
     * @return array<int|string, string>
     */
    private function libPatches(ComposerProject $project): array
    {
        /** @var array{extra: array{patches: array{'acme/lib': array<int|string, string>}}} $data */
        $data = json_decode($project->readFile('composer.json'), true, 512, JSON_THROW_ON_ERROR);

        return $data['extra']['patches']['acme/lib'];
    }
}
