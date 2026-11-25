<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Sync\Console;

use Glpi\Console\AbstractCommand;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Migration\LegacyJobScopeReport;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Uses GLPI's native console bootstrap and never executes or modifies interactions. */
final class ReviewActiveJobScopesCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this->setName('plugins:uxconnector:review-active-job-scopes')
            ->setDescription('Export a read-only entity-scope inventory of active UXConnector interactions.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->db instanceof \DBmysql) {
            $output->writeln('<error>A GLPI database connection is required.</error>');
            return self::FAILURE;
        }
        $report = (new LegacyJobScopeReport($this->db))->build();
        $output->writeln(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);
        return self::SUCCESS;
    }
}
