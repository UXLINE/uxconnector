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

namespace GlpiPlugin\Uxconnector\Domain\Engine\Event;

use DateTimeImmutable;
use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigName;
use GlpiPlugin\Uxconnector\Domain\Shared\Event\DomainEvent;

/**
 * Raised once a new AuthConfig aggregate has been created in memory
 * (before persistence — the Repository decides when to publish it,
 * see RULE-059 / ADR-057).
 */
final readonly class AuthConfigCreatedEvent implements DomainEvent
{
   private DateTimeImmutable $occurredAt;

   public function __construct(
        public AuthConfigName $name,
        public AuthMethod $authMethod
    ) {
       $this->occurredAt = new DateTimeImmutable();
   }

   public function occurredAt(): DateTimeImmutable {
       return $this->occurredAt;
   }

   public function name(): string {
       return 'engine.auth_config.created';
   }
}
