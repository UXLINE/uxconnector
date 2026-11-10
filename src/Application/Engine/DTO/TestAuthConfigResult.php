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

namespace GlpiPlugin\Uxconnector\Application\Engine\DTO;

/**
 * Outcome of a single "test this connection" attempt
 * (TestAuthConfigService).
 */
final readonly class TestAuthConfigResult
{
    public function __construct(
        public bool $success,
        public ?int $statusCode,
        public ?float $durationMs,
        public ?string $errorMessage
    ) {
    }
}
