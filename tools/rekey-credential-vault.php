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
 * Script opérationnel de rotation de clé pour les secrets AuthConfig,
 * à exécuter manuellement par un administrateur — jamais depuis un
 * navigateur, jamais automatiquement.
 *
 * PROCÉDURE :
 *   1. Conserver l'ancienne valeur de GLPI_INSTANCE_KEY (celle
 *      actuellement dans config/local_define.php) — noter-la avant
 *      toute modification.
 *   2. Choisir la nouvelle valeur, mais NE PAS encore l'appliquer
 *      dans config/local_define.php.
 *   3. Exécuter ce script avec les deux clés :
 *
 *        php tools/rekey-credential-vault.php \
 *            --old-key="<ancienne valeur de GLPI_INSTANCE_KEY>" \
 *            --new-key="<nouvelle valeur>"
 *
 *   4. Une fois le script terminé sans échec (voir le résumé affiché),
 *      appliquer la nouvelle valeur dans config/local_define.php.
 *
 * Tant que l'étape 4 n'a pas eu lieu, GLPI continue de fonctionner
 * normalement avec l'ancienne clé — ce script ne modifie que les
 * secrets stockés par UXConnector, jamais GLPI_INSTANCE_KEY
 * lui-même.
 *
 * DÉROGATION DOCUMENTÉE — convention d'inclusion GLPI non vérifiée :
 * l'exact mécanisme d'amorçage attendu pour un script CLI de plugin
 * GLPI 11 (bootstrap minimal vs contexte de session complet,
 * éventuel enregistrement en tant que commande Symfony Console) n'a
 * pas pu être vérifié dans ce bac à sable (aucun runtime GLPI
 * disponible). Ce script suit la convention `inc/includes.php`
 * utilisée par les front/*.php du plugin, à valider empiriquement sur
 * `helpline-datawise` avant utilisation en production — au même titre
 * que les autres REX déjà consignés pour ce projet.
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

// tools/ est au même niveau que front/ sous la racine du plugin —
// même chemin relatif vers GLPI que front/*.php.
require __DIR__ . '/../../../inc/includes.php';

use GlpiPlugin\Uxconnector\Application\Engine\Service\CredentialVaultRekeyService;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\EngineServiceProvider;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\Security\OpenSslCredentialVault;

if (PHP_SAPI !== 'cli') {
    die("This script must be run from the command line only.\n");
}

$options = getopt('', ['old-key:', 'new-key:']);

if (!isset($options['old-key'], $options['new-key']) || $options['old-key'] === '' || $options['new-key'] === '') {
    fwrite(STDERR, "Usage: php rekey-credential-vault.php --old-key=\"...\" --new-key=\"...\"\n");
    exit(1);
}

if ($options['old-key'] === $options['new-key']) {
    fwrite(STDERR, "--old-key and --new-key must be different.\n");
    exit(1);
}

$oldVault = new OpenSslCredentialVault($options['old-key']);
$newVault = new OpenSslCredentialVault($options['new-key']);

$service = new CredentialVaultRekeyService(
    EngineServiceProvider::authConfigRepository(),
    EngineServiceProvider::authConfigSecretStore()
);

echo "Rotating UXConnector AuthConfig secrets...\n";

$result = $service->execute($oldVault, $newVault);

printf(
    "Done: %d rotated, %d skipped (no stored secret), %d failed.\n",
    $result->rotated,
    $result->skipped,
    $result->failed
);

if (!$result->isFullySuccessful()) {
    fwrite(STDERR, "\nThe following AuthConfig secrets could NOT be rotated:\n");

   foreach ($result->errors as $authConfigId => $errorMessage) {
       fwrite(STDERR, sprintf("  - AuthConfig #%d: %s\n", $authConfigId, $errorMessage));
   }

    fwrite(STDERR, "\nDo NOT update GLPI_INSTANCE_KEY until every secret has rotated successfully.\n");

    exit(1);
}

echo "\nEvery secret rotated successfully. You may now update GLPI_INSTANCE_KEY\n";
echo "in config/local_define.php to the new value.\n";
