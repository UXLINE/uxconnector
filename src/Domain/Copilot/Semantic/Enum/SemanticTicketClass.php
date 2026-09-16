<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\Enum;

/**
 * Stable semantic classes produced by UXConnector's semantic engine.
 *
 * These values are deliberately independent from the GLPI declared ticket type
 * and from the registered ITIL category.
 */
enum SemanticTicketClass: string
{
    case TECHNICAL_INCIDENT = 'technical_incident';
    case USER_ASSISTANCE = 'user_assistance';
    case ACCESS_REQUEST = 'access_request';
    case USAGE_ERROR = 'usage_error';
    case OTHER = 'other';
    case UNCERTAIN = 'uncertain';

    /** @return list<string> */
   public static function values(): array {
       return array_map(
           static fn (self $case): string => $case->value,
           self::cases()
       );
   }
}
