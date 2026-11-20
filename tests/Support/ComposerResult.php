<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal\Tests\Support;

/**
 * The exit code and the full output of one Composer run.
 */
final class ComposerResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $output,
        public readonly string $commandLine,
    ) {
    }
}
