<?php

/**
 * -------------------------------------------------------------------------
 * UXConnector plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * @copyright HELPLINE
 * @license   GPL-3.0-or-later
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Sync\Policy;

use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;

/**
 * Locks security-sensitive built-in templates to their intended GLPI target.
 *
 * This does not remove any generic writer capability. It prevents a persisted
 * built-in Zendesk interaction from being repurposed to write another object
 * type while retaining the trusted template id and its post-processors.
 */
final class BuiltinTemplateTargetPolicy
{
    /** @var array<string, TargetItemtype> */
    private const ZENDESK_TARGETS = [
        'zendesk.users.bootstrap' => TargetItemtype::USER,
        'zendesk.groups.bootstrap' => TargetItemtype::GROUP,
        'zendesk.organizations.bootstrap' => TargetItemtype::ENTITY,
        'zendesk.itilcategories.bootstrap' => TargetItemtype::ITIL_CATEGORY,
        'zendesk.ticket-forms.bootstrap' => TargetItemtype::ITIL_CATEGORY,
        'zendesk.tickets.nonclosed.inbound' => TargetItemtype::TICKET,
        'zendesk.tickets.delta.inbound' => TargetItemtype::TICKET,
        'zendesk.tickets.active.inbound' => TargetItemtype::TICKET,
        'zendesk.tickets.inbound' => TargetItemtype::TICKET,
        'glpi_rest_v1.indigo.telecom.locations.normalize' => TargetItemtype::LINE,
        'glpi_rest_v1.indigo.network.locations.normalize' => TargetItemtype::NETWORK_EQUIPMENT,
        'glpi_rest_v1.indigo.microsoft.licences.normalize' => TargetItemtype::SOFTWARE_LICENSE,
    ];

    public function assertAllowed(?string $templateId, TargetItemtype $target): void
    {
        if ($templateId === null || !isset(self::ZENDESK_TARGETS[$templateId])) {
            return;
        }

        $expected = self::ZENDESK_TARGETS[$templateId];
        if ($target !== $expected) {
            throw new \DomainException(sprintf(
                'Built-in template "%s" is locked to target "%s" and cannot target "%s".',
                $templateId,
                $expected->value,
                $target->value,
            ));
        }
    }
}
