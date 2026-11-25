<?php

declare(strict_types=1);

// Source-only compatibility check using the real GLPI base classes, without a GLPI database.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$core = getenv('GLPI_TEST_SOURCE');
if (!$core || !is_file($core . '/src/CommonDBTM.php')) {
    throw new RuntimeException('GLPI_TEST_SOURCE must reference the GLPI source checkout.');
}
spl_autoload_register(static function (string $class) use ($core): void {
    $file = $core . '/src/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
foreach (['SyncJobItem', 'SyncExecutionItem', 'ObjectLinkItem'] as $name) {
    $class = 'GlpiPlugin\\Uxconnector\\Infrastructure\\Sync\\Persistence\\' . $name;
    $item = new $class();
    if (!$item instanceof CommonDBTM || $class::canView() || $item->canViewItem() || $item->can(28, 1)) {
        throw new LogicException('Unexpected generic API read access for ' . $name);
    }
    foreach (['canCreate', 'canUpdate', 'canDelete', 'canPurge'] as $method) {
        if ($class::$method()) {
            throw new LogicException('Unexpected generic API write access for ' . $name);
        }
    }
    // Trusted repositories retain GLPI's native persistence methods.
    foreach (['add', 'update', 'find', 'getFromDB'] as $method) {
        if (!method_exists($item, $method)) {
            throw new LogicException('Missing repository persistence method: ' . $method);
        }
    }
}
echo "OK: real GLPI CommonDBTM inheritance and API access signatures; all three internal itemtypes refuse generic CRUD.\n";
