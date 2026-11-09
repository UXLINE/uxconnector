<?php

declare(strict_types=1);

use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Runtime\SyncRuntimeGuard;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\SyncServiceProvider;

/** Adapter from GLPI CommonDBTM hooks to the connector-agnostic outbound runtime. */
function plugin_uxconnector_item_changed(CommonDBTM $item): void
{
    if (SyncRuntimeGuard::active()) {
        return;
    }

    $itemtype = match ($item::class) {
        Project::class => TargetItemtype::PROJECT,
        Ticket::class => TargetItemtype::TICKET,
        Computer::class => TargetItemtype::COMPUTER,
        Monitor::class => TargetItemtype::MONITOR,
        NetworkEquipment::class => TargetItemtype::NETWORK_EQUIPMENT,
        Peripheral::class => TargetItemtype::PERIPHERAL,
        Phone::class => TargetItemtype::PHONE,
        Printer::class => TargetItemtype::PRINTER,
        User::class => TargetItemtype::USER,
        default => null,
    };

    if ($itemtype === null) {
        return;
    }

    $itemId = (int) ($item->fields['id'] ?? 0);
    if ($itemId <= 0) {
        return;
    }

    try {
        $result = SyncServiceProvider::dispatchGlpiItemEventService()->execute($itemtype, $itemId, $item->fields);
        foreach ($result['errors'] as $error) {
            Toolbox::logInFile('uxconnector', sprintf("Outbound interaction failed: %s\n", $error));
        }
    } catch (\Throwable $exception) {
        Toolbox::logInFile('uxconnector', sprintf("Outbound dispatch failed: %s\n", $exception->getMessage()));
    }
}
