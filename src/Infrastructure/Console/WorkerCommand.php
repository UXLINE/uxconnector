<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Console;

use GlpiPlugin\Uxconnector\Infrastructure\Sync\SyncServiceProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** One bounded queue pass, suitable for multiple external scheduler processes. */
final class WorkerCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('plugins:uxconnector:worker')
            ->setDescription('Runs one pending UXConnector job, skipping streams owned by other workers.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!\Plugin::isPluginActive('uxconnector')) {
            $output->writeln('<error>UXConnector must be active before starting a worker.</error>');
            return Command::FAILURE;
        }
        // No HTTP endpoint, session cookie, subprocess, fork, or shell command.
        // OS access to GLPI configuration authorizes this system worker.
        // Targeted retries remain owned by the single GLPI ExecutionQueue task.
        $result = SyncServiceProvider::runQueuedExecutionsService()->execute(false);
        $output->writeln(sprintf(
            '%d job(s), %d succeeded, %d failed.',
            $result->totalJobs,
            $result->succeeded,
            $result->failed
        ));
        return $result->failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
