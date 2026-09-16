<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Tests\Unit\Application\Engine\Service;

use GlpiPlugin\Uxconnector\Application\Engine\Service\AssignConnectorTemplateService;
use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthConfigStatus;
use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigName;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\TestPath;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;
use GlpiPlugin\Uxconnector\Domain\Template\Entity\ConnectorTemplate;
use GlpiPlugin\Uxconnector\Domain\Template\Repository\ConnectorTemplateRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class AssignConnectorTemplateServiceTest extends TestCase
{
   public function testAssociatingJiraAppliesRecommendedTestPathAndRequiresRetest(): void {
       $connection = AuthConfig::create(
           new AuthConfigName('Legacy Jira'),
           new Endpoint('https://api.atlassian.com/ex/jira/cloud-id'),
           AuthMethod::BEARER_TOKEN,
           new TestPath('/'),
           'custom'
       );
       $connection->assignId(new AuthConfigId(1));
       $connection->markTesting();
       $connection->activate();

       $repo = new AssignConnectorTestRepo($connection);
       $templates = new AssignConnectorTestTemplates(new ConnectorTemplate(
           'jira',
           '1.0.0',
           'Jira Cloud',
           'Jira',
           'ti ti-brand-jira',
           'https://api.atlassian.com/ex/jira/{cloudId}',
           '/rest/api/3/project/search?maxResults=1',
           ['bearer_token'],
           []
       ));

       $service = new AssignConnectorTemplateService($repo, $templates);
       $service->execute(new AuthConfigId(1), 'jira');

       $updated = $repo->findById(new AuthConfigId(1));
       self::assertNotNull($updated);
       self::assertSame('jira', $updated->connectorId());
       self::assertSame('/rest/api/3/project/search?maxResults=1', $updated->testPath()->value());
       self::assertSame(AuthConfigStatus::DRAFT, $updated->status());
   }
}

final class AssignConnectorTestRepo implements AuthConfigRepositoryInterface
{
   public function __construct(private AuthConfig $item) {}
   public function save(AuthConfig $authConfig): AuthConfig {
      $this->item = $authConfig; return $authConfig; }
   public function findById(AuthConfigId $id): ?AuthConfig {
      return $id->value() === 1 ? $this->item : null; }
   public function findAll(): array {
      return [$this->item]; }
   public function delete(AuthConfigId $id): void {}
   public function exists(AuthConfigId $id): bool {
      return $id->value() === 1; }
}

final class AssignConnectorTestTemplates implements ConnectorTemplateRepositoryInterface
{
   public function __construct(private ConnectorTemplate $template) {}
   public function findAll(): array {
      return [$this->template]; }
   public function findById(string $id): ?ConnectorTemplate {
      return $id === $this->template->id ? $this->template : null; }
}
