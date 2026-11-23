<?php

declare(strict_types=1);

use GlpiPlugin\Uxconnector\Application\Engine\Service\BindLegacyCredentialsService;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\EngineServiceProvider;
use GlpiPlugin\Uxconnector\Infrastructure\Shared\Persistence\GlpiTransactionManager;

return static function (Migration $migration): void {
    global $DB;

    (new BindLegacyCredentialsService(
        EngineServiceProvider::authConfigRepository(),
        EngineServiceProvider::authConfigSecretStore(),
        EngineServiceProvider::credentialVault(),
        new GlpiTransactionManager($DB),
    ))->execute();
};
