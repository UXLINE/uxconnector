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

namespace GlpiPlugin\Uxconnector\Application\Engine\Exception;

use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthMethod;

/**
 * Raised when CreateAuthConfigService is asked to create an
 * AuthConfig using an AuthMethod that GenericHttpEngine cannot
 * actually execute (see AuthMethod::isImplemented()).
 *
 * CORRECTIF P0 (revue de code externe) : empêche la création d'une
 * configuration structurellement inutilisable, en complément du
 * filtrage déjà appliqué côté formulaire.
 */
final class AuthMethodNotSupportedException extends \InvalidArgumentException
{
   public static function forMethod(AuthMethod $authMethod): self {
       return new self(sprintf(
           'Authentication method "%s" is not yet supported by this plugin.',
           $authMethod->value
       ));
   }
}
