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
 * Script opÃ©rationnel de rotation de clÃ© pour les secrets AuthConfig,
 * Ã  exÃ©cuter manuellement par un administrateur â€” jamais depuis un
 * navigateur, jamais automatiquement.
 *
 * PROCÃ‰DURE :
 *   1. Conserver l'ancienne valeur de GLPI_INSTANCE_KEY (celle
 *      actuellement dans config/local_define.php) â€” noter-la avant
 *      toute modification.
 *   2. Choisir la nouvelle valeur, mais NE PAS encore l'appliquer
 *      dans config/local_define.php.
 *   3. ExÃ©cuter ce script avec les deux clÃ©s :
 *
 *        php tools/rekey-credential-vault.php \
 *            --old-key="<ancienne valeur de GLPI_INSTANCE_KEY>" \
 *            --new-key="<nouvelle valeur>"
 *
 *   4. Une fois le script terminÃ© sans Ã©chec (voir le rÃ©sumÃ© affichÃ©),
 *      appliquer la nouvelle valeur dans config/local_define.php.
 *
 * Tant que l'Ã©tape 4 n'a pas eu lieu, GLPI continue de fonctionner
 * normalement avec l'ancienne clÃ© â€” ce script ne modifie que les
 * secrets stockÃ©s par UXConnector, jamais GLPI_INSTANCE_KEY
 * lui-mÃªme.
 *
 * DÃ‰ROGATION DOCUMENTÃ‰E â€” convention d'inclusion GLPI non vÃ©rifiÃ©e :
 * l'exact mÃ©canisme d'amorÃ§age attendu pour un script CLI de plugin
 * GLPI 11 (bootstrap minimal vs contexte de session complet,
 * Ã©ventuel enregistrement en tant que commande Symfony Console) n'a
 * pas pu Ãªtre vÃ©rifiÃ© dans ce bac Ã  sable (aucun runtime GLPI
 * disponible). Ce script suit la convention `inc/includes.php`
 * utilisÃ©e par les front/*.php du plugin, Ã  valider empiriquement sur
 * `shared-test-instance` avant utilisation en production â€” au mÃªme titre
 * que les autres REX dÃ©jÃ  consignÃ©s pour ce projet.
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

// tools/ est au mÃªme niveau que front/ sous la racine du plugin â€”
// mÃªme chemin relatif vers GLPI que front/*.php.
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

