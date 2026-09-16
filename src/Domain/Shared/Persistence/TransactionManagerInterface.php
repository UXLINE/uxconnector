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

namespace GlpiPlugin\Uxconnector\Domain\Shared\Persistence;

/**
 * Port allowing an Application Service to demarcate a transaction
 * without knowing which persistence engine backs it.
 *
 * RULE-036/060 : a transaction covers exactly one use case and is
 * exclusively piloted by the Application Service — never by a
 * Repository, never by the Domain.
 */
interface TransactionManagerInterface
{
   public function begin(): void;

   public function commit(): void;

   public function rollback(): void;
}
