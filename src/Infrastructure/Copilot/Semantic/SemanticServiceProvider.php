<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Copilot\Semantic;

use GlpiPlugin\Uxconnector\Application\Copilot\Semantic\Service\ActivateSemanticEngineConfigService;
use GlpiPlugin\Uxconnector\Application\Copilot\Semantic\Service\AnalyzeSemanticTicketClassificationService;
use GlpiPlugin\Uxconnector\Application\Copilot\Semantic\Service\CreateSemanticEngineConfigService;
use GlpiPlugin\Uxconnector\Application\Copilot\Semantic\Service\DisableSemanticEngineConfigService;
use GlpiPlugin\Uxconnector\Application\Copilot\Semantic\Service\FinalizeSemanticTicketClassificationService;
use GlpiPlugin\Uxconnector\Application\Copilot\Semantic\Service\PrepareSemanticTicketClassificationService;
use GlpiPlugin\Uxconnector\Application\Copilot\Semantic\Service\SemanticEngineConnectionValidator;
use GlpiPlugin\Uxconnector\Application\Copilot\Semantic\Service\UpdateSemanticEngineConfigService;
use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\Port\SemanticClassificationProviderInterface;
use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\Port\SemanticTicketSourceInterface;
use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\Repository\SemanticEngineConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Infrastructure\Copilot\Semantic\Persistence\SemanticEngineConfigPersistenceMapper;
use GlpiPlugin\Uxconnector\Infrastructure\Copilot\Semantic\Persistence\SemanticEngineConfigRepository;
use GlpiPlugin\Uxconnector\Infrastructure\Copilot\Semantic\Provider\OpenAiCompatibleSemanticProvider;
use GlpiPlugin\Uxconnector\Infrastructure\Copilot\Semantic\Source\LocalGlpiSemanticTicketSource;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\EngineServiceProvider;

final class SemanticServiceProvider
{
   public static function repository(): SemanticEngineConfigRepositoryInterface {
       return new SemanticEngineConfigRepository(
           new SemanticEngineConfigPersistenceMapper()
       );
   }

   public static function connectionValidator(): SemanticEngineConnectionValidator {
       return new SemanticEngineConnectionValidator(
           EngineServiceProvider::authConfigRepository()
       );
   }

   public static function createService(): CreateSemanticEngineConfigService {
       return new CreateSemanticEngineConfigService(
           self::repository(),
           self::connectionValidator()
       );
   }

   public static function updateService(): UpdateSemanticEngineConfigService {
       return new UpdateSemanticEngineConfigService(
           self::repository(),
           self::connectionValidator()
       );
   }

   public static function activateService(): ActivateSemanticEngineConfigService {
       return new ActivateSemanticEngineConfigService(
           self::repository(),
           self::connectionValidator()
       );
   }

   public static function disableService(): DisableSemanticEngineConfigService {
       return new DisableSemanticEngineConfigService(
           self::repository()
       );
   }
   public static function classificationProvider(): SemanticClassificationProviderInterface {
       $config = self::repository()->findActive();

      if ($config === null) {
          throw new \RuntimeException(
              'No active semantic engine configuration is available.'
          );
      }

       $connection = self::connectionValidator()->requireUsable(
           new AuthConfigId($config->authConfigReference())
       );

       return new OpenAiCompatibleSemanticProvider(
           $config,
           $connection,
           EngineServiceProvider::httpEngine()
       );
   }
   public static function ticketSource(): SemanticTicketSourceInterface {
       return new LocalGlpiSemanticTicketSource();
   }

   public static function analysisService(): AnalyzeSemanticTicketClassificationService {
       $config = self::repository()->findActive();

      if ($config === null) {
          throw new \RuntimeException(
              'No active semantic engine configuration is available.'
          );
      }

       return new AnalyzeSemanticTicketClassificationService(
           self::ticketSource(),
           self::classificationProvider(),
           $config
       );
   }
   public static function prepareClassificationService(): PrepareSemanticTicketClassificationService {
       return new PrepareSemanticTicketClassificationService(self::ticketSource());
   }

   public static function finalizeClassificationService(): FinalizeSemanticTicketClassificationService {
       return new FinalizeSemanticTicketClassificationService(self::ticketSource());
   }
}
