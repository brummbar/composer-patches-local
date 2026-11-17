<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The command patches-local-check. It checks the patch rules, for example in CI.
 */
final class CheckCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('patches-local-check');
        $this->setDescription('Check that the project applies only patch files from its patches/ folder.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $check = new RuleCheck($this->requireComposer());
        $violations = $check->definitionViolations();
        if ($check->lockExists()) {
            $violations = array_merge($violations, $check->lockViolations());
        } else {
            $violations[] = '  - patches.lock.json does not exist. Run composer install or composer update first.';
        }
        if ($violations !== []) {
            $this->getIO()->writeError('<error>composer-patches-local: the patches of this project break the rules.</error>');
            $this->getIO()->writeError($violations);

            return 1;
        }
        $output->writeln('<info>All patches follow the rules.</info>');

        return 0;
    }
}
