<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal;

use Composer\Composer;
use Composer\IO\NullIO;
use cweagans\Composer\Patch;

/**
 * Checks that the project applies only patch files from its own patches/ folder.
 */
final class RuleCheck
{
    public function __construct(private readonly Composer $composer)
    {
    }

    /**
     * Returns the violations in the patch definitions of the root project.
     *
     * @return list<string>
     */
    public function definitionViolations(): array
    {
        $patches = ComposerPatches::find($this->composer);
        $resolver = new DependencyResolver($this->composer, new NullIO(), $patches);
        /** @var list<array<mixed>> $definitions */
        $definitions = [];
        $extra = $this->composer->getPackage()->getExtra();
        if (!empty($extra['patches'])) {
            $definitions[] = $extra['patches'];
        }
        $file = $patches->getConfig('patches-file');
        if (is_string($file) && is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data) && !empty($data['patches'])) {
                $definitions[] = $data['patches'];
            }
        }

        $violations = [];
        foreach ($definitions as $json) {
            /** @var array<string, list<Patch>> $grouped */
            $grouped = $resolver->findPatchesInJson((array) $json);
            foreach ($grouped as $list) {
                foreach ($list as $patch) {
                    $problem = PatchPath::problem($patch->url);
                    if ($problem !== null) {
                        $violations[] = $this->line($patch->package, $patch->description, $patch->url, $problem);
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * Returns the violations in patches.lock.json and in the files that it references.
     * Returns one violation when patches.lock.json does not exist.
     *
     * @return list<string>
     */
    public function lockViolations(): array
    {
        $patches = ComposerPatches::find($this->composer);
        if (!$patches->getLockFile()->exists()) {
            return ['  - patches.lock.json does not exist. Run composer install or composer update first.'];
        }
        $directory = ComposerPatches::projectDirectory($patches);
        $pluginNames = $this->lockedPluginNames();
        $violations = [];
        /** @var array<string, list<array<string, mixed>>> $locked */
        $locked = ComposerPatches::readLock($patches)['patches'] ?? [];
        foreach ($locked as $package => $list) {
            foreach ($list as $entry) {
                $patch = Patch::fromJson($entry + ['package' => $package]);
                $url = $patch->url ?? '';
                $description = $patch->description ?? '';
                $problem = PatchPath::problem($url);
                if ($problem === null) {
                    $problem = $this->fileProblem($directory . '/' . PatchPath::normalise($url), $patch->sha256 ?? null);
                }
                $provenance = $patch->extra['provenance'] ?? null;
                $fromDependency = is_string($provenance) && str_starts_with($provenance, 'dependency:');
                if ($problem === null && $fromDependency) {
                    $problem = $this->extraProblem($patch->extra)
                        ?? $this->pluginProblem($package, $pluginNames);
                }
                if ($problem !== null) {
                    $violations[] = $this->line($package, $description, $url, $problem);
                }
            }
        }

        return $violations;
    }

    /**
     * @param list<string> $violations
     */
    public static function fail(array $violations): never
    {
        $hint = self::importHint($violations);
        throw new \RuntimeException(
            "composer-patches-local: the patches of this project break the rules.\n"
            . implode("\n", $violations)
            . "\nEvery patch must be a file in the patches/ folder of the root project."
            . ($hint === null ? '' : "\n" . $hint)
        );
    }

    /**
     * Returns a hint line when one of the violation lines is about a URL, or null.
     *
     * @param list<string> $violations
     */
    public static function importHint(array $violations): ?string
    {
        foreach ($violations as $line) {
            if (str_ends_with($line, ': ' . PatchPath::URL_PROBLEM)) {
                return 'Run composer patches-local-import to download the remote patches of the root project into the patches/ folder.';
            }
        }

        return null;
    }

    private function fileProblem(string $file, ?string $sha256): ?string
    {
        if (!is_file($file)) {
            return 'the file does not exist';
        }
        if ($sha256 === null || $sha256 === '') {
            return 'patches.lock.json has no sha256 for the file';
        }

        return hash_file('sha256', $file) === $sha256 ? null : 'the file content does not match the sha256 in patches.lock.json';
    }

    /**
     * @return list<string>
     */
    private function lockedPluginNames(): array
    {
        $locker = $this->composer->getLocker();
        if (!$locker->isLocked()) {
            return [];
        }
        $names = [];
        foreach ($locker->getLockedRepository(true)->getPackages() as $lockedPackage) {
            if (in_array($lockedPackage->getType(), Repatch::PLUGIN_TYPES, true)) {
                $names[] = $lockedPackage->getName();
            }
        }

        return $names;
    }

    /**
     * @param list<string> $pluginNames
     */
    private function pluginProblem(string $package, array $pluginNames): ?string
    {
        return in_array($package, $pluginNames, true) ? 'a dependency must not patch a Composer plugin' : null;
    }

    /**
     * @param array<mixed> $extra
     */
    private function extraProblem(array $extra): ?string
    {
        $keys = array_diff(array_keys($extra), ['provenance', 'source']);
        if ($keys === []) {
            return null;
        }
        $names = implode(', ', $keys);

        return "patches.lock.json has extra keys that a dependency patch must not have: $names";
    }

    private function line(string $package, string $description, string $url, string $problem): string
    {
        return "  - $package, \"$description\" ($url): $problem";
    }
}
