<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use cweagans\Composer\Capability\Resolver\ResolverProvider;

/**
 * Gives composer-patches the resolver that replaces its dependency resolver.
 */
final class LocalResolverProvider implements ResolverProvider
{
    private readonly Composer $composer;

    private readonly IOInterface $io;

    private readonly PluginInterface $plugin;

    /**
     * @param array{composer: Composer, io: IOInterface, plugin: PluginInterface} $arguments
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
