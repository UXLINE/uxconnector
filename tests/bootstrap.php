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
 * Bootstrap PHPUnit — aucune logique métier.
 *
 * La suite Unit ne dépend jamais de GLPI (c'est tout l'intérêt de la
 * Clean Architecture appliquée à ce plugin : Domain/Application sont
 * testables avec zéro dépendance externe). Ce bootstrap ne charge
 * l'environnement GLPI complet que lorsque GLPI_ROOT est défini —
 * c'est-à-dire uniquement quand les suites Integration/EndToEnd sont
 * exécutées, typiquement en CI contre une instance GLPI de test ou
 * en local via docker-compose.
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$glpiRoot = getenv('GLPI_ROOT');

if ($glpiRoot !== false && $glpiRoot !== '') {
    /*
     * GLPI 11 defines GLPI_ROOT itself while loading its Composer
     * autoloader. Do not define the constant from the plugin bootstrap.
     */
    require_once rtrim($glpiRoot, '/') . '/vendor/autoload.php';

    if (!defined('GLPI_ROOT')) {
        throw new RuntimeException(
            'GLPI bootstrap did not define GLPI_ROOT.'
        );
    }

    require_once GLPI_ROOT . '/inc/includes.php';
}
