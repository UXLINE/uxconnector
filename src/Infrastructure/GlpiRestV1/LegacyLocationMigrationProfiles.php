<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\GlpiRestV1;

/**
 * Source-specific GLPI 10 Fields identifiers; never reuse numeric field IDs
 * across instances. These profiles contain no customer credentials/data.
 */
final class LegacyLocationMigrationProfiles
{
    public const NETWORK = 'glpi_rest_v1.indigo.network.locations.normalize';
    public const MICROSOFT = 'glpi_rest_v1.indigo.microsoft.licences.normalize';

    /** @return array<string,array{xml:string,label:string,block:string}> */
    public static function definitions(string $template): array
    {
        $inpark = [
            'entity_code' => ['xml' => 'PLUGIN_FIELDS_ENTITFIELDDROPDOWNS_ID', 'label' => 'Entité', 'block' => 'inpark'],
            'code_da' => ['xml' => 'CODEDAFIELD', 'label' => 'Code DA', 'block' => 'inpark'],
            'code_da_accounting' => ['xml' => 'CODEDACOMPTABLEFIELD', 'label' => 'Code DA Comptable', 'block' => 'inpark'],
            'code_ets' => ['xml' => 'CODEETFIELD', 'label' => 'Code ETS', 'block' => 'inpark'],
            'code_db' => ['xml' => 'CODEDBFIELD', 'label' => 'Code DB', 'block' => 'inpark'],
            'code_na' => ['xml' => 'CODENAFIELD', 'label' => 'Code NA', 'block' => 'inpark'],
            'code_nb' => ['xml' => 'CODENBFIELD', 'label' => 'Code NB', 'block' => 'inpark'],
        ];
        if ($template === self::NETWORK) {
            return $inpark + [
                'site_base' => ['xml' => 'IDBASEPARCFIELD', 'label' => 'IdBaseParcs', 'block' => 'inpark'],
                'site_code' => ['xml' => 'CODESITEFIELD', 'label' => 'Code Site', 'block' => 'inpark'],
                'longitude' => ['xml' => 'LONGITUDEFIELD', 'label' => 'Longitude', 'block' => 'inpark'],
                'latitude' => ['xml' => 'LATITUDEFIELD', 'label' => 'Latitude', 'block' => 'inpark'],
                'postcode' => ['xml' => 'CODEPOSTALFIELD', 'label' => 'Code Postal', 'block' => 'inpark'],
                'town' => ['xml' => 'VILLEFIELD', 'label' => 'Ville', 'block' => 'inpark'],
                'operation_start' => ['xml' => 'DBUTEXPLOITATIONFIELD', 'label' => 'Début Exploitation', 'block' => 'inpark'],
                'address' => ['xml' => 'ADRESSEFIELD', 'label' => 'Adresse', 'block' => 'inpark'],
                'equipment_type' => ['xml' => 'TYPEDEMATRIELFIELD', 'label' => 'Type de Matériel', 'block' => 'equipement'],
                'equipment_model' => ['xml' => 'MODLEFIELD', 'label' => 'Modèle', 'block' => 'equipement'],
                'serial' => ['xml' => 'NDESRIEFIELD', 'label' => 'N° de série', 'block' => 'equipement'],
                'status' => ['xml' => 'PLUGIN_FIELDS_STATUTFIELDDROPDOWNS_ID', 'label' => 'Statut', 'block' => 'equipement'],
                'ventilation_date' => ['xml' => 'DATEVENTILATIONFIELD', 'label' => 'Date ventilation', 'block' => 'equipement'],
                'depreciated' => ['xml' => 'DJAMORTIFIELD', 'label' => 'Déjà Amorti', 'block' => 'equipement'],
                'commissioning_date' => ['xml' => 'DATEDEMISEENSERVICEFIELD', 'label' => 'Date de mise en service', 'block' => 'equipement'],
                'stock_return_date' => ['xml' => 'DATERETOURSTOCKFIELD', 'label' => 'Date retour Stock', 'block' => 'equipement'],
                'comment' => ['xml' => 'COMMENTAIREFIELD', 'label' => 'Commentaires', 'block' => 'equipement'],
                'amount_ht' => ['xml' => 'PLUGIN_FIELDS_MONTANTHTFIELDDROPDOWNS_ID', 'label' => 'Montant HT', 'block' => 'equipement'],
                'monthly_amount' => ['xml' => 'PLUGIN_FIELDS_MONTANTMENSUELFIELDDROPDOWNS_ID', 'label' => 'Montant Mensuel', 'block' => 'equipement'],
            ];
        }
        if ($template === self::MICROSOFT) {
            return $inpark + [
                'product' => ['xml' => 'LICENCEFIELD', 'label' => 'Licences', 'block' => 'licence'],
                'beneficiary' => ['xml' => 'UTILISATEURFIELD', 'label' => 'Utilisateur', 'block' => 'licence'],
                'annual_price' => ['xml' => 'PRIXANNUELFIELD', 'label' => 'Prix annuel', 'block' => 'licence'],
                'monthly_price' => ['xml' => 'PRIXMENSUELFIELD', 'label' => 'Prix mensuel', 'block' => 'licence'],
                'activated_at' => ['xml' => 'DATEDACTIVATIONFIELD', 'label' => 'Date d’Activation', 'block' => 'licence'],
                'deactivated_at' => ['xml' => 'DATEDEDSACTIVATIONFIELD', 'label' => 'Date de Désactivation', 'block' => 'licence'],
                'function' => ['xml' => 'FONCTIONFIELD', 'label' => 'Fonction', 'block' => 'licence'],
                'registration_number' => ['xml' => 'MATRICULEFIELD', 'label' => 'Matricule', 'block' => 'licence'],
                'status' => ['xml' => 'STATUTFIELDTWO', 'label' => 'Statut', 'block' => 'licence'],
            ];
        }
        throw new \InvalidArgumentException('Unsupported GLPI REST v1 migration profile.');
    }

    /** @return array<string,list<string>> */
    public static function requiredTargetFields(string $template): array
    {
        return match ($template) {
            self::NETWORK => [
                'Location' => ['IdBaseParcs', 'Début Exploitation'],
                'NetworkEquipment' => [
                    'Entité Inpark', 'Code DA', 'Code DA Comptable', 'Code ETS', 'Code DB', 'Code NA', 'Code NB',
                    'Date de mise en service', 'Date ventilation', 'Date retour Stock', 'Montant Mensuel', 'Déjà Amorti',
                    'Montant HT', 'ID source GLPI 10', 'Source Last update', 'Source Creation date',
                ],
            ],
            self::MICROSOFT => ['SoftwareLicense' => [
                'Licences', 'Utilisateur', 'Matricule', 'Fonction', 'Statut',
                "Date d’activation", 'Date de désactivation', 'Prix annuel', 'Prix mensuel',
                'Entité', 'Code DA', 'Code DA Comptable', 'Code ETS', 'Code DB', 'Code NA', 'Code NB',
                'Chemin hiérarchique source', 'ID lieu source GLPI 10', 'Lieu parent historique',
                'Dernière modification source', 'Date création source',
            ]],
            default => throw new \InvalidArgumentException('Unsupported migration profile.'),
        };
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return [self::NETWORK, self::MICROSOFT];
    }
}
