<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal;

use Composer\Composer;
use Composer\IO\IOInterface;
use cweagans\Composer\Capability\Resolver\ResolverProvider;

/**
 * Gives composer-patches the resolver that replaces its dependency resolver.
 */
final class LocalResolverProvider implements ResolverProvider
{
    private readonly Composer $composer;

    private readonly IOInterface $io;

    private readonly Plugin $plugin;

    /**
     * @param array{composer: Composer, io: IOInterface, plugin: Plugin} $arguments
     */
    public function __construct(array $arguments)
    {
        $this->composer = $arguments['composer'];
        $this->io = $arguments['io'];
        $this->plugin = $arguments['plugin'];
    }

    public function getResolvers(): array
    {
        return [new DependencyResolver($this->composer, $this->io, $this->plugin)];
    }
}
