<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;

/**
 * Gives Composer the commands patches-local-check and patches-local-import.
 */
final class CommandProvider implements CommandProviderCapability
{
    public function getCommands(): array
    {
        return [new CheckCommand(), new ImportCommand()];
    }
}
