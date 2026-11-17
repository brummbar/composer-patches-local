<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;

/**
 * Gives Composer the command patches-local-check.
 */
final class CommandProvider implements CommandProviderCapability
{
    public function getCommands(): array
    {
        return [new CheckCommand()];
    }
}
