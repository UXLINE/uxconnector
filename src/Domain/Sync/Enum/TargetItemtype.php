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

namespace GlpiPlugin\Uxconnector\Domain\Sync\Enum;

/** Curated GLPI item types that the generic inbound writer may instantiate. */
enum TargetItemtype: string
{
    case COMPUTER = 'computer';
    case MONITOR = 'monitor';
    case NETWORK_EQUIPMENT = 'network_equipment';
    case PERIPHERAL = 'peripheral';
    case PHONE = 'phone';
    case SIM_CARD = 'sim_card';
    case LOCATION = 'location';
    case LINE = 'line';
    case SOFTWARE_LICENSE = 'software_license';
    case PRINTER = 'printer';
    case USER = 'user';
    case GROUP = 'group';
    case ENTITY = 'entity';
    case ITIL_CATEGORY = 'itil_category';
    case ITIL_FOLLOWUP = 'itil_followup';
    case DOCUMENT = 'document';
    case PROJECT = 'project';
    case TICKET = 'ticket';

    public function label(): string
    {
        return match ($this) {
            self::COMPUTER => 'Ordinateur',
            self::MONITOR => 'Écran',
            self::NETWORK_EQUIPMENT => 'Équipement réseau',
            self::PERIPHERAL => 'Périphérique',
            self::PHONE => 'Téléphone',
            self::SIM_CARD => 'Carte SIM (composant)',
            self::LOCATION => 'Lieu',
            self::LINE => 'Ligne',
            self::SOFTWARE_LICENSE => 'Licence',
            self::PRINTER => 'Imprimante',
            self::USER => 'Utilisateur',
            self::GROUP => 'Groupe',
            self::ENTITY => 'Entité',
            self::ITIL_CATEGORY => 'Catégorie ITIL',
            self::ITIL_FOLLOWUP => 'Suivi ITIL',
            self::DOCUMENT => 'Document',
            self::PROJECT => 'Projet',
            self::TICKET => 'Ticket',
        };
    }

    public function glpiClassName(): string
    {
        return match ($this) {
            self::COMPUTER => '\\Computer',
            self::MONITOR => '\\Monitor',
            self::NETWORK_EQUIPMENT => '\\NetworkEquipment',
            self::PERIPHERAL => '\\Peripheral',
            self::PHONE => '\\Phone',
            self::SIM_CARD => '\\Item_DeviceSimcard',
            self::LOCATION => '\\Location',
            self::LINE => '\\Line',
            self::SOFTWARE_LICENSE => '\\SoftwareLicense',
            self::PRINTER => '\\Printer',
            self::USER => '\\User',
            self::GROUP => '\\Group',
            self::ENTITY => '\\Entity',
            self::ITIL_CATEGORY => '\\ITILCategory',
            self::ITIL_FOLLOWUP => '\\ITILFollowup',
            self::DOCUMENT => '\\Document',
            self::PROJECT => '\\Project',
            self::TICKET => '\\Ticket',
        };
    }
}
