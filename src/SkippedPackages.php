<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal;

/**
 * Gives access to the packages whose local patches are not ready on disk.
 *
 * The other classes use this interface and not the class Plugin. Composer loads an updated plugin under a renamed class.
 * That class does not extend Plugin, but it still implements this interface.
 */
interface SkippedPackages
{
    /**
     * @return list<string>
     */
    public function skippedPackages(): array;

    /**
     * @param list<string> $names
     */
    public function setSkippedPackages(array $names): void;
}
