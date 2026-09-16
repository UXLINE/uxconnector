<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Copilot\Semantic\Service;

use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\Entity\SemanticEngineConfig;

final class PowerPlatformSemanticPolicy
{
   public const CLASSIFIER_VERSION = '1.0.0';
   public const CONFIDENCE_THRESHOLD = 0.7000;
   public const PROVIDER_CONTRACT = 'power-platform-prompt-json-v1';
   public const MODEL = 'managed-by-power-platform';
   public const DEFAULT_BATCH_SIZE = 20;

   public static function ephemeralConfig(): SemanticEngineConfig {
       $config = SemanticEngineConfig::create(
           'Power Platform semantic classifier',
           1,
           self::MODEL,
           '/managed-by-power-platform',
           self::DEFAULT_BATCH_SIZE,
           self::CONFIDENCE_THRESHOLD,
           0.0,
           self::CLASSIFIER_VERSION
       );

       $config->activate();

       return $config;
   }
}
