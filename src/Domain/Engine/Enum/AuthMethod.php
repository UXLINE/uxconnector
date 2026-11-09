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

namespace GlpiPlugin\Uxconnector\Domain\Engine\Enum;

/**
 * Authentication methods supported by the generic ConnectorEngine.
 *
 * The set is intentionally limited to what the GenericHttpEngine can
 * execute without connector-specific code (ADR-002 : the plugin no
 * longer hardcodes one client per third-party product).
 */
enum AuthMethod: string
{
    case NONE = 'none';
    case BASIC = 'basic';
    case API_KEY = 'api_key';
    case BEARER_TOKEN = 'bearer_token';
    case OAUTH2_CLIENT_CREDENTIALS = 'oauth2_client_credentials';
    case OAUTH2_PASSWORD = 'oauth2_password';
    case OAUTH2_AUTHORIZATION_CODE = 'oauth2_authorization_code';
    case GLPI_REST_V1 = 'glpi_rest_v1';

    /**
     * Returns the human-readable label of the method (fr_FR).
     */
    public function label(): string
    {
        return match ($this) {
            self::NONE => 'Aucune authentification',
            self::BASIC => 'Authentification Basic',
            self::API_KEY => 'Clé d\'API',
            self::BEARER_TOKEN => 'Jeton Bearer statique',
            self::OAUTH2_CLIENT_CREDENTIALS => 'OAuth2 - Client Credentials',
            self::OAUTH2_PASSWORD => 'OAuth2 - Password Grant',
            self::OAUTH2_AUTHORIZATION_CODE => 'OAuth2 - Authorization Code',
            self::GLPI_REST_V1 => 'GLPI REST v1 - App-Token + User-Token',
        };
    }

    /**
     * Whether this method requires a secret to be stored in the
     * CredentialVault (as opposed to AuthMethod::NONE).
     */
    public function requiresVaultedCredentials(): bool
    {
        return $this !== self::NONE;
    }

    /**
     * Whether this method relies on the OAuth2 token exchange flow
     * (token endpoint, refresh token, expiry) rather than a single
     * static secret.
     */
    public function isOAuth2(): bool
    {
        return in_array(
            $this,
            [self::OAUTH2_CLIENT_CREDENTIALS, self::OAUTH2_PASSWORD, self::OAUTH2_AUTHORIZATION_CODE],
            true,
        );
    }

    /**
     * Whether GenericHttpEngine can actually execute this method
     * today (see AuthenticationStrategyFactory, which throws for
     * every case where this returns false).
     *
     * CORRECTIF P0 (revue de code externe) : avant ce correctif, le
     * formulaire de création listait AuthMethod::cases() sans filtre
     * - un administrateur pouvait donc créer une AuthConfig en OAuth2
     * qui échouerait systématiquement à la première exécution. Cette
     * méthode est l'unique source de vérité utilisée à la fois pour
     * filtrer les choix affichés (Presentation) et pour refuser la
     * création côté serveur (Application), afin que les deux ne
     * puissent jamais diverger.
     *
     * OAUTH2_CLIENT_CREDENTIALS est désormais implémenté
     * (AuthenticationStrategyFactory + OAuth2TokenClient) : c'est un
     * flux purement machine-à-machine (identifiant + secret client),
     * sans interaction utilisateur, compatible avec une exécution
     * planifiée (CronTask) - voir OAuth2ClientCredentialsAuthenticationStrategy.
     *
     * OAUTH2_AUTHORIZATION_CODE reste délibérément non implémenté :
     * ce flux nécessite une redirection navigateur et un consentement
     * utilisateur interactif, structurellement incompatible avec une
     * tâche planifiée headless - ce n'est pas un manque temporaire
     * mais une incompatibilité de nature avec le cas d'usage de ce
     * plugin.
     */
    public function isImplemented(): bool
    {
        return $this !== self::OAUTH2_AUTHORIZATION_CODE;
    }
}
