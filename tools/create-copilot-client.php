<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}
if ($argc < 3) {
    fwrite(STDERR, "Usage: php tools/create-copilot-client.php <name> <local|auth_config_id>\n");
    exit(1);
}

$glpiRoot = dirname(__DIR__, 3);
require $glpiRoot . '/inc/includes.php';

use GlpiPlugin\Uxconnector\Infrastructure\Copilot\Persistence\CopilotClientRepository;

$name = trim((string) $argv[1]);
$providerArg = strtolower(trim((string) $argv[2]));
if ($name === '') {
    fwrite(STDERR, "Invalid name.\n");
    exit(1);
}

try {
    if ($providerArg === 'local') {
        $providerType = 'local_glpi';
        $authConfigId = 0;
    } else {
        $providerType = 'glpi_hl_api';
        $authConfigId = (int) $providerArg;
        if ($authConfigId <= 0) {
            throw new \InvalidArgumentException('Remote mode requires a positive auth_config_id.');
        }
    }

    $created = (new CopilotClientRepository())->create($name, $providerType, $authConfigId);
    fwrite(STDOUT, "Copilot client created. Store this key now; it will not be shown again:\n{$created['key']}\n");
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
