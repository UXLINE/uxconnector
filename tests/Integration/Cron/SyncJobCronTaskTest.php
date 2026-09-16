<?php

/**
 * -------------------------------------------------------------------------
 * UXConnector plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * @copyright HELPLINE
 * @license   GPL-3.0-or-later
 * -------------------------------------------------------------------------
 *
 * NÃ©cessite une instance GLPI de test rÃ©elle (GLPI_ROOT dÃ©fini) ainsi
 * qu'un serveur HTTP de test accessible en boucle locale
 * (UXCONNECTOR_TEST_HTTP_ENDPOINT, ex. dÃ©marrÃ© via
 * `php -S 127.0.0.1:8099` servant un payload JSON statique â€” voir
 * les scripts de smoke test dÃ©jÃ  utilisÃ©s en dÃ©veloppement).
 *
 * CORRECTIF P0 (revue de code externe) : la version prÃ©cÃ©dente de ce
 * fichier appelait directement SyncJobCronTask::cronSyncJob(), qui
 * exÃ©cute *tous* les SyncJob actifs de la base â€” pas seulement celui
 * crÃ©Ã© par le test. LancÃ© sur une instance partagÃ©e (shared-test-instance
 * ou toute base contenant dÃ©jÃ  des synchronisations rÃ©elles actives),
 * ce test aurait dÃ©clenchÃ© leur exÃ©cution en mÃªme temps que la sienne.
 * Cette classe distingue dÃ©sormais explicitement :
 *  - un scÃ©nario isolÃ© (ExecuteSyncJobService, ne touche que le job du
 *    test), qui reste le test par dÃ©faut ;
 *  - un scÃ©nario couvrant le comportement rÃ©el de cronSyncJob() (Â« tous
 *    les jobs actifs Â»), verrouillÃ© derriÃ¨re une variable d'environnement
 *    explicite, Ã  n'activer QUE sur une base GLPI jetable et dÃ©diÃ©e.
 * Le Computer potentiellement crÃ©Ã© par GlpiItemWriter est Ã©galement
 * nettoyÃ© en tearDown(), ce que la version prÃ©cÃ©dente omettait.
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Tests\Integration\Cron;

use Computer;
use CronTask;
use GlpiPlugin\Uxconnector\Application\Sync\Command\ExecuteSyncJobCommand;
use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigName;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncDirection;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\AuthConfigReference;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalIdField;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\FieldMapping;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\MatchField;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ResourcePath;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobName;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\EngineServiceProvider;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Cron\SyncJobCronTask;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence\SyncExecutionPersistenceMapper;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence\SyncExecutionRepository;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\SyncServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GlpiPlugin\Uxconnector\Infrastructure\Sync\Cron\SyncJobCronTask
 */
#[Group('integration')]
#[CoversClass(SyncJobCronTask::class)]
final class SyncJobCronTaskTest extends TestCase
{
   private const TEST_SERIAL = 'UXCONNECTOR-INTEGRATION-TEST-SERIAL';

   private ?AuthConfig $authConfig = null;
   private ?SyncJob $syncJob = null;

   protected function setUp(): void {
      if (!defined('GLPI_ROOT')) {
          self::markTestSkipped('Requires a real GLPI instance (GLPI_ROOT).');
      }

      if (getenv('UXCONNECTOR_TEST_HTTP_ENDPOINT') === false) {
          self::markTestSkipped(
              'Requires UXCONNECTOR_TEST_HTTP_ENDPOINT (a local test HTTP server ' .
              'serving a JSON array of {"serialNumber": "...", "mail": "..."} items, ' .
              'with at least one item using serialNumber="' . self::TEST_SERIAL . '").'
          );
      }
   }

    /**
     * Safe by construction: executes only the SyncJob created by this
     * test, through ExecuteSyncJobService directly â€” never touches any
     * other SyncJob that may exist in the database. This is the test
     * that should run in ordinary CI against a shared test database.
     */
   public function testExecuteSyncJobServiceRunsOnlyThisTestsJobAndRecordsAnExecution(): void {
       $this->createFixtures();

       $execution = SyncServiceProvider::executeSyncJobService()->execute(
           new ExecuteSyncJobCommand($this->syncJob->id())
       );

       self::assertNotSame('failed', $execution->status()->value, (string) $execution->errorMessage());

       $history = new SyncExecutionRepository(new SyncExecutionPersistenceMapper());
       self::assertNotEmpty(
           $history->findBySyncJobId($this->syncJob->id()),
           'Expected at least one SyncExecution to have been recorded.'
       );
   }

    /**
     * Covers the actual behaviour of the GLPI scheduler entry point:
     * cronSyncJob() runs EVERY currently active SyncJob, not just one.
     *
     * DANGER : ce test exÃ©cute rÃ©ellement toutes les synchronisations
     * actives de la base ciblÃ©e. Il ne doit JAMAIS Ãªtre lancÃ© contre
     * une instance partagÃ©e ou de production â€” uniquement contre une
     * base GLPI jetable, dÃ©diÃ©e aux tests d'intÃ©gration, oÃ¹ la
     * prÃ©sence d'autres SyncJob actifs est soit impossible soit sans
     * consÃ©quence. D'oÃ¹ la double garde : le skip par dÃ©faut, levÃ©
     * uniquement par une variable d'environnement au nom explicite.
     */
   public function testCronSyncJobExecutesEveryActiveSyncJobInTheDatabase(): void {
      if (getenv('UXCONNECTOR_ALLOW_DESTRUCTIVE_CRON_TEST') !== '1') {
          self::markTestSkipped(
              'SyncJobCronTask::cronSyncJob() executes EVERY active SyncJob ' .
              'currently in the database, not just the one created by this test. ' .
              'Set UXCONNECTOR_ALLOW_DESTRUCTIVE_CRON_TEST=1 only against a ' .
              'disposable, dedicated test GLPI instance â€” never against a shared ' .
              'or production database.'
          );
      }

       $this->createFixtures();

       $task = new CronTask();
       $returnCode = SyncJobCronTask::cronSyncJob($task);

       self::assertSame(1, $returnCode, 'Expected the CronTask to report it ran at least one job.');

       $history = new SyncExecutionRepository(new SyncExecutionPersistenceMapper());
       self::assertNotEmpty($history->findBySyncJobId($this->syncJob->id()));
   }

   private function createFixtures(): void {
       $authConfigRepository = EngineServiceProvider::authConfigRepository();
       $this->authConfig = AuthConfig::create(
           new AuthConfigName('Integration Test - Cron AuthConfig'),
           new Endpoint((string) getenv('UXCONNECTOR_TEST_HTTP_ENDPOINT')),
           AuthMethod::NONE
       );
       // CORRECTIF (revue de code externe) : sans ces deux appels,
       // l'AuthConfig reste en statut DRAFT â€” AuthConfig::isUsable()
       // exige ACTIVE, donc ExecuteSyncJobService aurait rejetÃ©
       // l'exÃ©cution avec SyncJobAuthConfigUnavailableException et ce
       // test aurait Ã©chouÃ© Ã  sa propre assertion de statut.
       $this->authConfig->markTesting();
       $this->authConfig->activate();
       $this->authConfig = $authConfigRepository->save($this->authConfig);

       $syncJobRepository = SyncServiceProvider::syncJobRepository();
       $this->syncJob = SyncJob::create(
           new SyncJobName('Integration Test - Cron SyncJob'),
           new AuthConfigReference($this->authConfig->id()->value()),
           SyncDirection::INBOUND,
           new ResourcePath('/echo-items'),
           TargetItemtype::COMPUTER,
           new MatchField('serial'),
           new ExternalIdField('id'),
           [new FieldMapping('serialNumber', 'serial', required: true)]
       );
       $this->syncJob->activate();
       $this->syncJob = $syncJobRepository->save($this->syncJob);
   }

    /**
     * Cleans up not only the fixtures this test created directly, but
     * also the Computer GlpiItemWriter may have written, and every
     * SyncExecution the run(s) may have produced â€” the two gaps the
     * code review flagged: "il pollue progressivement la CMDB de
     * test" and the execution history growing unboundedly on a
     * persistent CI database.
     */
   protected function tearDown(): void {
      if ($this->syncJob?->id() !== null) {
          $executionRepository = new SyncExecutionRepository(new SyncExecutionPersistenceMapper());

         foreach ($executionRepository->findBySyncJobId($this->syncJob->id()) as $execution) {
            if ($execution->id() !== null) {
                $executionRepository->delete($execution->id());
            }
         }

          SyncServiceProvider::syncJobRepository()->delete($this->syncJob->id());
      }

      if ($this->authConfig?->id() !== null) {
          EngineServiceProvider::authConfigRepository()->delete($this->authConfig->id());
      }

       $computer = new Computer();
       $rows = $computer->find(['serial' => self::TEST_SERIAL]);

      foreach ($rows as $row) {
          $computer->delete(['id' => $row['id']], true);
      }

       $this->authConfig = null;
       $this->syncJob = null;
   }
}

