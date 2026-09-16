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
 * RULE-COMPOSER-005 : ce fichier respecte le namespace obligatoire
 * `GlpiPlugin\Uxconnector\`, y compris pour cette classe technique de
 * persistance — GLPI 11 résout nativement les itemtypes namespacés
 * (FQCN), la convention legacy `PluginUxconnectorXxx` sans namespace
 * n'est donc plus nécessaire.
 *
 * RULE-032 / AI-ENTITY-002 : cette classe n'est jamais utilisée
 * directement par le Domaine ou l'Application. Elle est un détail
 * d'implémentation exclusif de AuthConfigRepository. L'agrégat
 * `Domain\Engine\Entity\AuthConfig` n'hérite jamais de CommonDBTM.
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Engine\Persistence;

use CommonDBTM;

/**
 * GLPI Active Record model backing the `glpi_plugin_uxconnector_authconfigs`
 * table. Kept intentionally "dumb": no business rule lives here, only
 * the technical plumbing GLPI's core (rights, entities, search engine)
 * expects from a CommonDBTM-derived class.
 */
final class AuthConfigItem extends CommonDBTM
{
    // Must stay in sync with PluginUxconnectorProfile::getRightDefinitions()
    // (inc/profile.class.php) — the single source of truth for this
    // right's name, its registration via Migration::addRight(), and
    // its display in the Profile rights matrix. PHP does not allow a
    // method call in a static property's default value, so this
    // string cannot be derived automatically; it is duplicated here
    // by convention rather than by language enforcement.
   public static $rightname = 'plugin_uxconnector_authconfig';

   public static function getTable($classname = null): string {
       return 'glpi_plugin_uxconnector_authconfigs';
   }

   public static function getTypeName($nb = 0): string {
       return \_n('Authentication configuration', 'Authentication configurations', $nb, 'uxconnector');
   }

   public function getName($options = []): string {
       return $this->fields['name'] ?? '';
   }

    /**
     * @return array<int, array<string, mixed>>
     */
   public function rawSearchOptions(): array {
       $options = parent::rawSearchOptions();

       $options[] = [
           'id'       => '2',
           'table'    => $this->getTable(),
           'field'    => 'endpoint',
           'name'     => \__('Endpoint', 'uxconnector'),
           'datatype' => 'string',
       ];

       $options[] = [
           'id'       => '5',
           'table'    => $this->getTable(),
           'field'    => 'test_path',
           'name'     => \__('Test path', 'uxconnector'),
           'datatype' => 'string',
       ];

       $options[] = [
           'id'       => '3',
           'table'    => $this->getTable(),
           'field'    => 'auth_method',
           'name'     => \__('Authentication method', 'uxconnector'),
           'datatype' => 'string',
       ];

       $options[] = [
           'id'       => '4',
           'table'    => $this->getTable(),
           'field'    => 'status',
           'name'     => \__('Status', 'uxconnector'),
           'datatype' => 'string',
       ];

       $options[] = [
           'id'            => '19',
           'table'         => $this->getTable(),
           'field'         => 'date_mod',
           'name'          => \__('Last update'),
           'datatype'      => 'datetime',
           'massiveaction' => false,
       ];

       $options[] = [
           'id'            => '121',
           'table'         => $this->getTable(),
           'field'         => 'date_creation',
           'name'          => \__('Creation date'),
           'datatype'      => 'datetime',
           'massiveaction' => false,
       ];

       return $options;
   }
}
