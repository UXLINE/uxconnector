<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\GlpiHl;

use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;

final class GlpiAssetReferenceDefinition
{
    /**
     * @return array{
     *   type_field:string,
     *   type_class:class-string<\CommonDBTM>,
     *   model_field:string,
     *   model_class:class-string<\CommonDBTM>
     * }|null
     */
    public static function for(TargetItemtype $itemtype): ?array
    {
        return match ($itemtype) {
            TargetItemtype::COMPUTER => [
                'type_field' => 'computertypes_id',
                'type_class' => \ComputerType::class,
                'model_field' => 'computermodels_id',
                'model_class' => \ComputerModel::class,
            ],
            TargetItemtype::MONITOR => [
                'type_field' => 'monitortypes_id',
                'type_class' => \MonitorType::class,
                'model_field' => 'monitormodels_id',
                'model_class' => \MonitorModel::class,
            ],
            TargetItemtype::NETWORK_EQUIPMENT => [
                'type_field' => 'networkequipmenttypes_id',
                'type_class' => \NetworkEquipmentType::class,
                'model_field' => 'networkequipmentmodels_id',
                'model_class' => \NetworkEquipmentModel::class,
            ],
            TargetItemtype::PERIPHERAL => [
                'type_field' => 'peripheraltypes_id',
                'type_class' => \PeripheralType::class,
                'model_field' => 'peripheralmodels_id',
                'model_class' => \PeripheralModel::class,
            ],
            TargetItemtype::PHONE => [
                'type_field' => 'phonetypes_id',
                'type_class' => \PhoneType::class,
                'model_field' => 'phonemodels_id',
                'model_class' => \PhoneModel::class,
            ],
            TargetItemtype::PRINTER => [
                'type_field' => 'printertypes_id',
                'type_class' => \PrinterType::class,
                'model_field' => 'printermodels_id',
                'model_class' => \PrinterModel::class,
            ],
            default => null,
        };
    }

    public static function supports(TargetItemtype $itemtype): bool
    {
        return self::for($itemtype) !== null;
    }
}
