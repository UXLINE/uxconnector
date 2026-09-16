<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Copilot\Semantic\Persistence;

use CommonDBTM;

final class SemanticEngineConfigItem extends CommonDBTM
{
   public static $rightname = 'plugin_uxconnector_authconfig';

   public static function getTable($classname = null): string {
       return 'glpi_plugin_uxconnector_semanticengineconfigs';
   }

   public static function getTypeName($nb = 0): string {
       return \_n('Semantic engine configuration', 'Semantic engine configurations', $nb, 'uxconnector');
   }

   public function getName($options = []): string {
       return $this->fields['name'] ?? '';
   }
}
