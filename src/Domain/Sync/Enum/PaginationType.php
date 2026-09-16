<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Sync\Enum;

/** Generic pagination families supported by the Sync runtime. */
enum PaginationType: string
{
    case NONE = 'none';
    case OFFSET = 'offset';
    case COUNT_OFFSET = 'count_offset';
    case CURSOR = 'cursor';
    case NEXT_LINK = 'next_link';

   public function label(): string {
       return match ($this) {
           self::NONE => 'Aucune',
           self::OFFSET => 'Offset',
           self::COUNT_OFFSET => 'Offset par taille de page',
           self::CURSOR => 'Curseur',
           self::NEXT_LINK => 'Lien suivant',
       };
   }
}
