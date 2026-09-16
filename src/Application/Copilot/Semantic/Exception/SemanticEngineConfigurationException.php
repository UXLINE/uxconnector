<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Copilot\Semantic\Exception;

use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\ValueObject\SemanticEngineConfigId;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;

final class SemanticEngineConfigurationException extends \RuntimeException
{
   public static function unknownAuthConfig(AuthConfigId $id): self {
       return new self(sprintf(
           'Semantic engine references unknown UXConnector connection #%d.',
           $id->value()
       ));
   }

   public static function incompatibleConnector(AuthConfigId $id, string $connectorId): self {
       return new self(sprintf(
           'UXConnector connection #%d uses connector type "%s"; semantic engines require "openai-compatible".',
           $id->value(),
           $connectorId
       ));
   }

   public static function inactiveAuthConfig(AuthConfigId $id, string $status): self {
       return new self(sprintf(
           'UXConnector connection #%d is "%s"; it must be active before the semantic engine can be activated.',
           $id->value(),
           $status
       ));
   }

   public static function anotherConfigAlreadyActive(SemanticEngineConfigId $id): self {
       return new self(sprintf(
           'Semantic engine configuration #%d is already active. Disable it before activating another one.',
           $id->value()
       ));
   }
}
