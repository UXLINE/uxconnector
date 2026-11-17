<?php

/**
 * Compatibility entry point for the retired partial credential rotation tool.
 * GLPIKey and the secured_fields hook must rotate ALL GLPI/plugin secrets together.
 * No key is accepted in argv, no session is bootstrapped and no database write occurs here.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

fwrite(STDERR, "Use the native GLPI command from the GLPI directory:\n");
fwrite(STDERR, "  php bin/console security:change_key\n");
fwrite(STDERR, "Back up the database and config/glpicrypt.key together before native key rotation.\n");
fwrite(STDERR, "This legacy tool no longer performs partial rotation or accepts keys as arguments.\n");
exit(1);
