<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal\Tests\Integration;

use Brummbar\ComposerPatchesLocal\Tests\Support\ComposerResult;
use Brummbar\ComposerPatchesLocal\Tests\Support\ComposerTestCase;
use Brummbar\ComposerPatchesLocal\Tests\Support\FileSystem;
use Brummbar\ComposerPatchesLocal\Tests\Support\FixturePackage;
use Brummbar\ComposerPatchesLocal\Tests\Support\LayeredFixtures as Layers;

/**
 * Tests how the plugin resolves, relocks and applies dependency patches, with real Composer.
 */
final class DependencyPatchesTest extends ComposerTestCase
{
    private const PATCHED_BY_MODULE_ONE = "line one\nline two patched by module-one\nline three\n";

    private const ROOT_PATCH = 'patches/lib.patch';

    public function testALocalPatchOfADependencyAppliesTwoLevelsUp(): void
    {
        $project = $this->layeredProject(Layers::moduleOneWithLocalPatch());

        $this->assertComposerSucceeded($project->composer(['install']));

        $this->assertProjectFileSame(self::PATCHED_BY_MODULE_ONE, $project, 'vendor/acme/lib/lib.txt');
        $patch = $project->readPatchesLock()['patches']['acme/lib'][0];
        $this->assertSame('patches/dependencies/acme/module-one/acme/lib/lib-line-two.patch', $patch['url']);
        $this->assertSame('dependency:acme/module-one', $patch['extra']['provenance']);
    }

    public function testADependencyCannotRunAProgramThroughExtraFreeform(): void
    {
        $project = $this->createProject();
        $marker = $project->projectPath('marker.txt');
        // The hunk context is not in lib.txt, so git apply rejects the patch.
        $rejectedPatch = "--- a/lib.txt\n+++ b/lib.txt\n@@ -1,3 +1,3 @@\n no such line\n-line two\n+changed\n line three\n";
        $moduleOne = Layers::moduleOne(
            '1.0.0',
            ['acme/lib' => [[
                'description' => 'Change line two',
                'url' => Layers::LOCAL_PATCH_PATH,
                'extra' => ['freeform' => ['executable' => 'touch', 'args' => $marker]],
            ]]],
            [Layers::LOCAL_PATCH_PATH => $rejectedPatch],
        );
        Layers::addLayers($project, $moduleOne);
        $project->setRoot(Layers::site())->withPlugin();

        $result = $project->composer(['update']);

        $this->assertComposerFailed($result);
        $this->assertOutputContains('No available patcher was able to apply patch', $result);
        $this->assertFileDoesNotExist($marker);
    }

    public function testTwoDependenciesCanUseTheSamePatchFileName(): void
    {
        $project = $this->createProject();
        $project->addPackage(Layers::lib())
            ->addPackage(Layers::moduleOne(
                '1.0.0',
                ['acme/lib' => ['Change line one' => 'patches/lib.patch']],
                ['patches/lib.patch' => Layers::lineOnePatch('module-one')],
            ))
            ->addPackage(new FixturePackage(
                'acme/module-two',
                '1.0.0',
                ['patches/lib.patch' => Layers::lineThreePatch('module-two')],
                ['acme/module-one' => '^1.0'],
                ['patches' => ['acme/lib' => ['Change line three' => 'patches/lib.patch']]],
            ))
            ->setRoot(Layers::site())
            ->withPlugin();

        $this->assertComposerSucceeded($project->composer(['install']));

        $this->assertProjectFileSame(
            "line one patched by module-one\nline two\nline three patched by module-two\n",
            $project,
            'vendor/acme/lib/lib.txt',
        );
        $this->assertCount(2, $project->readPatchesLock()['patches']['acme/lib']);
    }

    public function testAFreshInstallWithAnEmptyCacheAppliesThePatchAndTheNextInstallDoesNothing(): void
    {
        $project = $this->layeredProject(Layers::moduleOneWithLocalPatch());

        $first = $project->composer(['install']);

        $this->assertComposerSucceeded($first);
        $this->assertProjectFileSame(self::PATCHED_BY_MODULE_ONE, $project, 'vendor/acme/lib/lib.txt');
        $lock = $project->readFile('patches.lock.json');
        $installed = $project->readFile('vendor/composer/installed.json');

        $second = $project->composer(['install']);

        $this->assertComposerSucceeded($second);
        $this->assertNothingReinstalled($second);
        $this->assertProjectFileSame($lock, $project, 'patches.lock.json');
        $this->assertProjectFileSame($installed, $project, 'vendor/composer/installed.json');
    }

    public function testATargetUpdatedInTheRunWhereTheDeclaringPackageDropsItsPatchIsNotPatched(): void
    {
        $project = $this->layeredProject(Layers::moduleOneWithLocalPatch());
        $this->assertComposerSucceeded($project->composer(['install']));
        $newLibFile = "line one\nline two changed upstream\nline three\n";
        $project->addPackage(new FixturePackage('acme/lib', '1.1.0', ['lib.txt' => $newLibFile]))
            ->addPackage(Layers::moduleOne('1.1.0'));

        $this->assertComposerSucceeded($project->composer(['update']));

        $this->assertProjectFileSame($newLibFile, $project, 'vendor/acme/lib/lib.txt');
        $this->assertArrayNotHasKey('acme/lib', $project->readPatchesLock()['patches']);
    }

    public function testADeclaringPackageCanAddALocalPatchWhileTheTargetStaysTheSame(): void
    {
        $project = $this->layeredProject(Layers::moduleOne());
        $this->assertComposerSucceeded($project->composer(['install']));
        $project->addPackage(Layers::moduleOneWithLocalPatch('1.1.0'));

        $this->assertComposerSucceeded($project->composer(['update', 'acme/module-one']));

        $this->assertProjectFileSame(self::PATCHED_BY_MODULE_ONE, $project, 'vendor/acme/lib/lib.txt');
        $this->assertSame(
            'patches/dependencies/acme/module-one/acme/lib/lib-line-two.patch',
            $project->readPatchesLock()['patches']['acme/lib'][0]['url'],
        );
    }

    public function testADeclaringPackageCanAddARemotePatchWhileTheTargetStaysTheSame(): void
    {
        $project = $this->layeredProject(Layers::moduleOne());
        $this->assertComposerSucceeded($project->composer(['install']));
        $project->addPackage(Layers::moduleOne('1.1.0', ['acme/lib' => ['Change line two' => Layers::REMOTE_PATCH_URL]]));

        $this->assertComposerSucceeded($project->composer(['update', 'acme/module-one']));

        $this->assertProjectFileSame("line one\nline two patched by remote\nline three\n", $project, 'vendor/acme/lib/lib.txt');
        $this->assertSame(
            'patches/dependencies/acme/module-one/acme/lib/lib-line-two.patch',
            $project->readPatchesLock()['patches']['acme/lib'][0]['url'],
        );
    }

    public function testAnUpdateOfOnlyTheTargetAppliesTheDependencyPatchInTheSameRun(): void
    {
        $project = $this->layeredProject(Layers::moduleOneWithLocalPatch());
        $this->assertComposerSucceeded($project->composer(['install']));
        $lock = $project->readFile('patches.lock.json');
        $project->addPackage(new FixturePackage('acme/lib', '1.1.0', ['lib.txt' => "line one\nline two\nline three\nline four\n"]));

        $result = $project->composer(['update', 'acme/lib']);

        $this->assertComposerSucceeded($result);
        $this->assertOutputContains('Upgrading acme/lib', $result);
        $this->assertProjectFileSame(
            "line one\nline two patched by module-one\nline three\nline four\n",
            $project,
            'vendor/acme/lib/lib.txt',
        );
        $this->assertStringNotContainsString('reinstalling', $result->output, $this->describe('The plugin must not reinstall.', $result));
        $this->assertProjectFileSame($lock, $project, 'patches.lock.json');
    }

    public function testPatchesWithTheSameContentFromTwoLayersApplyOnce(): void
    {
        $remotePatch = (string) file_get_contents(__DIR__ . '/../fixtures/remote-patches/lib-line-two.patch');
        $project = $this->createProject();
        $project->addPackage(Layers::lib())
            ->addPackage(Layers::moduleOne(
                '1.0.0',
                ['acme/lib' => ['Local copy' => Layers::LOCAL_PATCH_PATH]],
                [Layers::LOCAL_PATCH_PATH => $remotePatch],
            ))
            ->addPackage(new FixturePackage(
                'acme/module-two',
                '1.0.0',
                [],
                ['acme/module-one' => '^1.0'],
                ['patches' => ['acme/lib' => ['Remote' => Layers::REMOTE_PATCH_URL]]],
            ))
            ->setRoot(Layers::site())
            ->withPlugin();

        $this->assertComposerSucceeded($project->composer(['install']));

        $this->assertProjectFileSame("line one\nline two patched by remote\nline three\n", $project, 'vendor/acme/lib/lib.txt');
        $this->assertCount(1, $project->readPatchesLock()['patches']['acme/lib']);
    }

    public function testInstallPatchesAgainWhenARootPatchChangesAndTheLockIsRewritten(): void
    {
        $project = $this->layeredProject(Layers::moduleOne(), $this->rootWithPatchesFile(Layers::lineTwoPatch('root-one')));
        $this->assertComposerSucceeded($project->composer(['install']));
        $this->assertProjectFileSame("line one\nline two patched by root-one\nline three\n", $project, 'vendor/acme/lib/lib.txt');

        FileSystem::write($project->projectPath(self::ROOT_PATCH), Layers::lineTwoPatch('root-two'));
        $this->assertComposerSucceeded($project->composer(['patches-relock']));
        $this->assertComposerSucceeded($project->composer(['install']));

        $this->assertProjectFileSame("line one\nline two patched by root-two\nline three\n", $project, 'vendor/acme/lib/lib.txt');

        FileSystem::write($project->projectPath('patches.json'), '{"patches": {}}');
        $this->assertComposerSucceeded($project->composer(['patches-relock']));
        $this->assertComposerSucceeded($project->composer(['install']));

        $this->assertProjectFileSame(Layers::LIB_FILE, $project, 'vendor/acme/lib/lib.txt');
    }

    public function testTheEndStepRunsBeforeAScaffoldPlugin(): void
    {
        $project = $this->layeredProject(Layers::moduleOne(), Layers::site([], [], ['aaa/scaffold' => '*']));
        $project->addPackage($this->scaffoldFixture());
        $this->assertComposerSucceeded($project->composer(['install']));
        $this->assertProjectFileSame(Layers::LIB_FILE, $project, 'lib-copy.txt');
        $project->addPackage(Layers::moduleOneWithLocalPatch('1.1.0'));

        $this->assertComposerSucceeded($project->composer(['update', 'acme/module-one']));

        $this->assertProjectFileSame(self::PATCHED_BY_MODULE_ONE, $project, 'vendor/acme/lib/lib.txt');
        $this->assertProjectFileSame(self::PATCHED_BY_MODULE_ONE, $project, 'lib-copy.txt');
    }

    public function testASecondUpdateWithoutChangesWritesAndReinstallsNothing(): void
    {
        $project = $this->layeredProject(Layers::moduleOneWithLocalPatch());
        $this->assertComposerSucceeded($project->composer(['install']));
        $this->assertComposerSucceeded($project->composer(['update']));
        $lock = $project->readFile('patches.lock.json');
        $installed = $project->readFile('vendor/composer/installed.json');

        $result = $project->composer(['update']);

        $this->assertComposerSucceeded($result);
        $this->assertNothingReinstalled($result);
        $this->assertProjectFileSame($lock, $project, 'patches.lock.json');
        $this->assertProjectFileSame($installed, $project, 'vendor/composer/installed.json');
        $this->assertProjectFileSame(self::PATCHED_BY_MODULE_ONE, $project, 'vendor/acme/lib/lib.txt');
    }

    public function testAFreshInstallWithoutTheResolverSettingFailsWithTheUpstreamErrorForALocalPatch(): void
    {
        $project = $this->layeredProject(Layers::moduleOneWithLocalPatch(), null, false);

        $result = $project->composer(['install']);

        $this->assertComposerFailed($result);
        $this->assertOutputContains('The "patches/lib-line-two.patch" file could not be downloaded', $result);
    }

    public function testAFreshInstallWithoutTheResolverSettingFailsWithAClearError(): void
    {
        $project = $this->layeredProject(Layers::moduleOne(), null, false);

        $result = $project->composer(['install']);

        $this->assertComposerFailed($result);
        $this->assertOutputContains('"disable-resolvers"', $result);
        $this->assertOutputContains('"\\\\cweagans\\\\Composer\\\\Resolver\\\\Dependencies"', $result);
    }

    public function testAnActivePluginStopsAtTheStartWhenTheResolverSettingIsMissing(): void
    {
        $project = $this->layeredProject(Layers::moduleOne());
        $this->assertComposerSucceeded($project->composer(['install']));
        $project->withPlugin(false);

        $result = $project->composer(['install']);

        $this->assertComposerFailed($result);
        $this->assertOutputContains('"disable-resolvers"', $result);
        $this->assertStringNotContainsString('Installing', $result->output);
    }

    public function testAFailedPatchInAFreshInstallMakesComposerFailAndTheNextRunAppliesTheFixedPatch(): void
    {
        $project = $this->layeredProject(Layers::moduleOne(), $this->rootWithPatchesFile("--- a/lib.txt\n+++ b/lib.txt\n@@ -1,1 +1,1 @@\n-no such line\n+x\n"));

        $this->assertComposerFailed($project->composer(['install']));

        FileSystem::write($project->projectPath(self::ROOT_PATCH), Layers::lineTwoPatch('root'));
        $this->assertComposerSucceeded($project->composer(['patches-relock']));
        $this->assertComposerSucceeded($project->composer(['install']));
        $this->assertProjectFileSame("line one\nline two patched by root\nline three\n", $project, 'vendor/acme/lib/lib.txt');
    }

    public function testAFailedPatchInTheEndStepMakesComposerFailAndTheNextRunAppliesTheFixedPatch(): void
    {
        $project = $this->layeredProject(Layers::moduleOne(), $this->rootWithPatchesFile(Layers::lineTwoPatch('root-one')));
        $this->assertComposerSucceeded($project->composer(['install']));

        FileSystem::write($project->projectPath(self::ROOT_PATCH), "--- a/lib.txt\n+++ b/lib.txt\n@@ -1,1 +1,1 @@\n-no such line\n+x\n");
        $this->assertComposerSucceeded($project->composer(['patches-relock']));
        $this->assertComposerFailed($project->composer(['install']));

        FileSystem::write($project->projectPath(self::ROOT_PATCH), Layers::lineTwoPatch('root-two'));
        $this->assertComposerSucceeded($project->composer(['patches-relock']));
        $this->assertComposerSucceeded($project->composer(['install']));
        $this->assertProjectFileSame("line one\nline two patched by root-two\nline three\n", $project, 'vendor/acme/lib/lib.txt');
    }

    public function testUpdateWithoutInstallDoesNotRelockAtTheEndAndSaysWhy(): void
    {
        $project = $this->layeredProject(Layers::moduleOne());
        $this->assertComposerSucceeded($project->composer(['install']));
        $project->addPackage(Layers::moduleOneWithLocalPatch('1.1.0'));
        $lock = $project->readFile('patches.lock.json');

        $result = $project->composer(['update', '--no-install', 'acme/module-one']);

        $this->assertComposerSucceeded($result);
        $this->assertOutputContains('did not install the packages', $result);
        $this->assertProjectFileSame($lock, $project, 'patches.lock.json');
    }

    public function testAChangedPatchOfThisPluginShowsTheWarningForPlugins(): void
    {
        $notesPatch = static fn (string $line): string => "--- /dev/null\n+++ b/NOTES.txt\n@@ -0,0 +1 @@\n+$line\n";
        $patchFile = 'patches/plugin-notes.patch';
        $root = Layers::site(
            [$patchFile => $notesPatch('one')],
            ['patches' => ['brummbar/composer-patches-local' => ['Add notes' => $patchFile]]],
        );
        $project = $this->layeredProject(Layers::moduleOne(), $root);

        $first = $project->composer(['install']);

        $this->assertComposerSucceeded($first);
        $this->assertProjectFileSame("one\n", $project, 'vendor/brummbar/composer-patches-local/NOTES.txt');
        $this->assertStringNotContainsString('is a Composer plugin', $first->output, $this->describe('The first install must not warn.', $first));

        $second = $project->composer(['install']);

        $this->assertComposerSucceeded($second);
        $this->assertStringNotContainsString('is a Composer plugin', $second->output, $this->describe('An install without changes must not warn.', $second));

        FileSystem::write($project->projectPath($patchFile), $notesPatch('two'));
        $third = $project->composer(['update', '--lock']);

        $this->assertComposerSucceeded($third);
        $this->assertOutputContains('brummbar/composer-patches-local is a Composer plugin', $third);
    }

    public function testADependencyPatchForAComposerPluginStopsTheRun(): void
    {
        $plugin = $this->hookPluginFixture();
        $patchFile = 'patches/hook-notes.patch';
        $moduleOne = new FixturePackage(
            'acme/module-one',
            '1.0.0',
            [$patchFile => "--- /dev/null\n+++ b/NOTES.txt\n@@ -0,0 +1 @@\n+patched by module-one\n"],
            ['acme/hook' => '^1.0'],
            ['patches' => ['acme/hook' => ['Add notes' => $patchFile]]],
        );
        $project = $this->createProject();
        $project->addPackage($plugin)
            ->addPackage($moduleOne)
            ->addPackage(Layers::moduleTwo())
            ->setRoot(Layers::site())
            ->withPlugin();

        $result = $project->composer(['update']);

        $this->assertComposerFailed($result);
        $this->assertOutputContains('acme/module-one declares patches for the Composer plugin acme/hook', $result);
        $this->assertOutputContains('Only the root project can patch a Composer plugin', $result);
    }

    public function testAnEmptyPatchListForAComposerPluginDoesNotStopTheRun(): void
    {
        $moduleOne = new FixturePackage(
            'acme/module-one',
            '1.0.0',
            ['patches/lib.patch' => Layers::lineOnePatch('module-one')],
            ['acme/hook' => '^1.0', 'acme/lib' => '^1.0'],
            ['patches' => ['acme/hook' => [], 'acme/lib' => ['Change line one' => 'patches/lib.patch']]],
        );
        $project = $this->createProject();
        $project->addPackage($this->hookPluginFixture())
            ->addPackage(Layers::lib())
            ->addPackage($moduleOne)
            ->addPackage(Layers::moduleTwo())
            ->setRoot(Layers::site())
            ->withPlugin();

        $this->assertComposerSucceeded($project->composer(['update']));

        $this->assertProjectFileSame("line one patched by module-one\nline two\nline three\n", $project, 'vendor/acme/lib/lib.txt');
    }

    private function hookPluginFixture(): FixturePackage
    {
        $pluginFiles = [
            'src/Plugin.php' => "<?php\nnamespace Acme\\Hook;\nuse Composer\\Composer;\nuse Composer\\IO\\IOInterface;\nuse Composer\\Plugin\\PluginInterface;\n"
                . "final class Plugin implements PluginInterface\n{\n"
                . "    public function activate(Composer \$composer, IOInterface \$io): void {}\n"
                . "    public function deactivate(Composer \$composer, IOInterface \$io): void {}\n"
                . "    public function uninstall(Composer \$composer, IOInterface \$io): void {}\n}\n",
        ];
        return new FixturePackage(
            'acme/hook',
            '1.0.0',
            $pluginFiles,
            ['composer-plugin-api' => '^2.0'],
            ['class' => 'Acme\\Hook\\Plugin'],
            'composer-plugin',
            ['psr-4' => ['Acme\\Hook\\' => 'src/']],
        );
    }

    private function rootWithPatchesFile(string $patch): FixturePackage
    {
        return Layers::site([
            'patches.json' => json_encode(['patches' => ['acme/lib' => ['Root patch' => self::ROOT_PATCH]]], JSON_THROW_ON_ERROR),
            self::ROOT_PATCH => $patch,
        ]);
    }

    private function assertNothingReinstalled(ComposerResult $result): void
    {
        $this->assertStringNotContainsString('reinstalling', $result->output, $this->describe('Composer must not reinstall.', $result));
        $this->assertStringNotContainsString('- Installing', $result->output, $this->describe('Composer must not install.', $result));
        $this->assertStringNotContainsString('- Removing', $result->output, $this->describe('Composer must not remove.', $result));
    }

    /**
     * A fixture plugin that copies lib.txt to the project root, like Drupal scaffold.
     */
    private function scaffoldFixture(): FixturePackage
    {
        $code = <<<'PHP'
        <?php

        namespace Fixture\Scaffold;

        use Composer\Composer;
        use Composer\EventDispatcher\EventSubscriberInterface;
        use Composer\IO\IOInterface;
        use Composer\Plugin\PluginInterface;
        use Composer\Script\ScriptEvents;

        final class ScaffoldPlugin implements PluginInterface, EventSubscriberInterface
        {
            private Composer $composer;

            public function activate(Composer $composer, IOInterface $io): void
            {
                $this->composer = $composer;
            }

            public function deactivate(Composer $composer, IOInterface $io): void
            {
            }

            public function uninstall(Composer $composer, IOInterface $io): void
            {
            }

            public static function getSubscribedEvents(): array
            {
                return [
                    ScriptEvents::POST_UPDATE_CMD => 'copy',
                    ScriptEvents::POST_INSTALL_CMD => 'copy',
                ];
            }

            public function copy(): void
            {
                $vendor = $this->composer->getConfig()->get('vendor-dir');
                copy($vendor . '/acme/lib/lib.txt', dirname($vendor) . '/lib-copy.txt');
            }
        }
        PHP;

        return new FixturePackage(
            // The name sorts before this plugin, so the scaffold listener runs first at equal priority.
            'aaa/scaffold',
            '1.0.0',
            ['src/ScaffoldPlugin.php' => $code . "\n"],
            ['composer-plugin-api' => '^2.0'],
            ['class' => 'Fixture\\Scaffold\\ScaffoldPlugin'],
            'composer-plugin',
            ['psr-4' => ['Fixture\\Scaffold\\' => 'src/']],
        );
    }
}
