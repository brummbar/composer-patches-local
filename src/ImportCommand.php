<?php

declare(strict_types=1);

namespace Brummbar\ComposerPatchesLocal;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The command patches-local-import. It downloads the remote patches of the root project into patches/.
 */
final class ImportCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('patches-local-import');
        $this->setDescription('Download the remote patches of the root project into its patches/ folder.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        (new RootPatchImport($this->requireComposer(), $this->getIO()))->run();

        return 0;
    }
}
