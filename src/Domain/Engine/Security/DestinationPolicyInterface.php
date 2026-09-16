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

namespace GlpiPlugin\Uxconnector\Domain\Engine\Security;

use GlpiPlugin\Uxconnector\Domain\Engine\Exception\ForbiddenDestinationException;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;

/**
 * Port through which any code about to contact an Endpoint — at
 * configuration time (CreateAuthConfigService) or at request time
 * (GenericHttpEngine) — asserts that its destination is actually safe
 * to reach.
 *
 * CORRECTIF SÉCURITÉ (revue de code externe, SSRF) : ni la validation
 * de format d'`Endpoint` (schéma, longueur) ni le durcissement des
 * redirections HTTP du lot P0 ne protègent contre une requête
 * délibérément dirigée vers une adresse dangereuse dès le premier
 * appel (boucle locale, lien-local — qui inclut les métadonnées
 * cloud sur 169.254.169.254). `Endpoint` reste volontairement
 * permissif sur le choix de l'hôte : ce plugin a précisément vocation
 * à contacter des systèmes internes légitimes (Zabbix, API métier sur
 * réseau privé) — la décision de sécurité (quel hôte est acceptable)
 * ne peut donc pas être une simple validation de format, elle doit
 * vivre dans une politique dédiée, invoquée à chaque utilisation
 * réelle d'un Endpoint.
 */
interface DestinationPolicyInterface
{
    /**
     * @throws ForbiddenDestinationException if the endpoint's host
     *         resolves to an address that must never be contacted by
     *         this plugin, or cannot be resolved at all.
     */
   public function assertAllowed(Endpoint $endpoint): void;
}
