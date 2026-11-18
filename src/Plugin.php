<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\InstallerEvent;
use Composer\Installer\InstallerEvents;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PreCommandRunEvent;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use cweagans\Composer\Capability\Resolver\ResolverProvider;

/**
 * The Composer plugin. It connects the parts of composer-patches-local to Composer events.
 */
final class Plugin implements PluginInterface, EventSubscriberInterface, Capable, SkippedPackages
{
    public const NAME = 'brummbar/composer-patches-local';

    /**
     * The key of the plugin in composer.json, patches.lock.json and installed.json.
     */
    public const KEY = 'composer-patches-local';

    private const NOT_INSTALLED_MESSAGE = '<info>composer-patches-local: Composer did not install the packages, so the plugin does not lock or apply patches.</info>';

    private Composer $composer;

    private IOInterface $io;

    /** True when the plugin was active at the start of an install run. */
    private bool $installRunStarted = false;

    /** True when this run acts like an update: an update, or an install with a missing or outdated lock. */
    private bool $relockRun = false;

    private bool $startRelockDone = false;

    /** True when the command is "install --download-only". Composer does not tell script events about this option. */
    private bool $downloadOnly = false;

    /** True when the command is "reinstall". Composer does not dispatch pre-operations-exec for it. */
    private bool $reinstall = false;

    /** @var list<string> */
    private array $skippedPackages = [];

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    public function getCapabilities(): array
    {
        return [
            ResolverProvider::class => LocalResolverProvider::class,
            CommandProviderCapability::class => CommandProvider::class,
        ];
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PluginEvents::PRE_COMMAND_RUN => 'readCommand',
            ScriptEvents::PRE_INSTALL_CMD => 'startInstall',
            ScriptEvents::PRE_UPDATE_CMD => 'startUpdate',
            InstallerEvents::PRE_OPERATIONS_EXEC => 'relockAtStart',
            // The priority must be above 0, so that this runs before Drupal scaffold.
            ScriptEvents::POST_UPDATE_CMD => ['finishUpdate', 10],
            ScriptEvents::POST_INSTALL_CMD => ['finishInstall', 10],
            // These run after composer-patches (priority 10) applied the patches.
            PackageEvents::POST_PACKAGE_INSTALL => ['recordPatches', -10],
            PackageEvents::POST_PACKAGE_UPDATE => ['recordPatches', -10],
        ];
    }

    /**
     * @return list<string>
     */
    public function skippedPackages(): array
    {
        return $this->skippedPackages;
    }

    /**
     * @param list<string> $names
     */
    public function setSkippedPackages(array $names): void
    {
        $this->skippedPackages = $names;
    }

    public function checkConfiguration(): void
    {
        ComposerPatches::assertConfigured(ComposerPatches::find($this->composer));
    }

    public function readCommand(PreCommandRunEvent $event): void
    {
        $this->reinstall = $event->getCommand() === 'reinstall';
        $input = $event->getInput();
        $this->downloadOnly = $input->hasOption('download-only') && $input->getOption('download-only');
    }

    public function startInstall(): void
    {
        if ($this->downloadOnly) {
            return;
        }
        $this->checkConfiguration();
        $this->installRunStarted = true;
        $check = new RuleCheck($this->composer);
        $violations = $check->definitionViolations();
        if (Fingerprint::isCurrent($this->composer)) {
            $violations = array_merge($violations, $check->lockViolations());
        } else {
            $this->relockRun = true;
        }
        $this->failOn($violations);
        if ($this->reinstall && $this->relockRun) {
            (new Relock($this->composer, $this))->run([]);
            $this->startRelockDone = true;
        }
    }

    public function startUpdate(): void
    {
        $this->checkConfiguration();
        $this->relockRun = true;
        $this->failOn((new RuleCheck($this->composer))->definitionViolations());
    }

    /**
     * Locks the patches that are ready now. The packages of this run are not on disk yet.
     */
    public function relockAtStart(InstallerEvent $event): void
    {
        if ($this->downloadOnly || !$this->relockRun) {
            return;
        }
        $skipped = [];
        foreach ($event->getTransaction()?->getOperations() ?? [] as $operation) {
            if ($operation instanceof InstallOperation) {
                $skipped[] = $operation->getPackage()->getName();
            } elseif ($operation instanceof UpdateOperation) {
                $skipped[] = $operation->getTargetPackage()->getName();
            }
        }
        (new Relock($this->composer, $this))->run($skipped);
        $this->startRelockDone = true;
    }

    public function finishUpdate(Event $event): void
    {
        if ($this->relockRun && !$this->startRelockDone) {
            $this->io->writeError(self::NOT_INSTALLED_MESSAGE);
            return;
        }
        $this->finishWithRelock($event);
    }

    public function finishInstall(Event $event): void
    {
        if ($this->downloadOnly) {
            $this->io->writeError(self::NOT_INSTALLED_MESSAGE);
            return;
        }
        if ($this->relockRun || (!$this->installRunStarted && !Fingerprint::isCurrent($this->composer))) {
            $this->finishWithRelock($event);
            return;
        }
        $this->failOn((new RuleCheck($this->composer))->lockViolations());
        (new Repatch($this->composer, $this->io))->run($event->isDevMode());
    }

    public function recordPatches(PackageEvent $event): void
    {
        (new Repatch($this->composer, $this->io))->record($event);
    }

    private function finishWithRelock(Event $event): void
    {
        $this->checkConfiguration();
        $patches = ComposerPatches::find($this->composer);
        (new Relock($this->composer, $this))->run([], true);
        $referenced = [];
        $lockedPatches = ComposerPatches::readLock($patches)['patches'] ?? [];
        /** @var array<string, list<array<mixed>>> $lockedPatches */
        foreach ($lockedPatches as $list) {
            foreach ($list as $entry) {
                $url = $entry['url'] ?? '';
                $referenced[] = is_string($url) ? $url : '';
            }
        }
        CopyCleanup::run(ComposerPatches::projectDirectory($patches), $referenced);
        $this->failOn((new RuleCheck($this->composer))->lockViolations());
        (new Repatch($this->composer, $this->io))->run($event->isDevMode());
    }

    /**
     * @param list<string> $violations
     */
    private function failOn(array $violations): void
    {
        if ($violations !== []) {
            RuleCheck::fail($violations);
        }
    }
}
