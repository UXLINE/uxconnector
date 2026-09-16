<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Tests\Unit\Infrastructure\GlpiHl;

use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;
use GlpiPlugin\Uxconnector\Infrastructure\GlpiHl\GlpiAssetReferenceDefinition;
use PHPUnit\Framework\TestCase;

final class GlpiAssetReferenceDefinitionTest extends TestCase
{
    public function testAllSixGlpiAssetTypesAreSupported(): void
    {
        foreach ([
            TargetItemtype::COMPUTER,
            TargetItemtype::MONITOR,
            TargetItemtype::PHONE,
            TargetItemtype::PERIPHERAL,
            TargetItemtype::PRINTER,
            TargetItemtype::NETWORK_EQUIPMENT,
        ] as $itemtype) {
            self::assertTrue(GlpiAssetReferenceDefinition::supports($itemtype));
            self::assertNotNull(GlpiAssetReferenceDefinition::for($itemtype));
        }
    }

    public function testComputerReferenceFieldsMatchGlpiSchema(): void
    {
        self::assertSame([
            'type_field' => 'computertypes_id',
            'type_class' => 'ComputerType',
            'model_field' => 'computermodels_id',
            'model_class' => 'ComputerModel',
        ], GlpiAssetReferenceDefinition::for(TargetItemtype::COMPUTER));
    }

    public function testNonAssetTypeIsNotSupported(): void
    {
        self::assertFalse(GlpiAssetReferenceDefinition::supports(TargetItemtype::TICKET));
        self::assertNull(GlpiAssetReferenceDefinition::for(TargetItemtype::TICKET));
    }
}
