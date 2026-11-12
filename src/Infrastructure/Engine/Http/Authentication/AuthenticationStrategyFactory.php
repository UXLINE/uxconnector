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

namespace GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\Authentication;

use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\Security\DestinationPolicyInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Exception\HttpEngineException;

/**
 * Resolves the AuthenticationStrategy matching an AuthMethod.
 *
 * RULE-AUTHTYPE-003 (adapted) : behaviour tied to an authentication
 * mode belongs here, in a dedicated Infrastructure component - never
 * scattered as if/else chains across GenericHttpEngine or any
 * Application Service.
 */
final class AuthenticationStrategyFactory
{
    public function __construct(
        private readonly OAuth2TokenClient $oauth2TokenClient,
        private readonly DestinationPolicyInterface $destinationPolicy
    ) {
    }

    /**
     * @throws HttpEngineException if $authMethod requires a credential
     *         that was not supplied, is not yet supported (Authorization
     *         Code - see AuthMethod::isImplemented()'s doc-block), or -
     *         for Client Credentials - the stored secret is not a valid
     *         OAuth2 configuration payload.
     */
    public function create(AuthConfig $authConfig, #[\SensitiveParameter] ?string $secret): AuthenticationStrategy
    {
        $authMethod = $authConfig->authMethod();
        return match ($authMethod) {
            AuthMethod::NONE => new NoneAuthenticationStrategy(),
            AuthMethod::BASIC => new BasicAuthenticationStrategy(
                $this->requireSecret($secret, $authMethod),
            ),
            AuthMethod::API_KEY => new ApiKeyAuthenticationStrategy(
                $this->requireSecret($secret, $authMethod),
            ),
            AuthMethod::BEARER_TOKEN => new BearerTokenAuthenticationStrategy(
                $this->requireSecret($secret, $authMethod),
            ),
            AuthMethod::OAUTH2_CLIENT_CREDENTIALS => $this->createOAuth2ClientCredentials(
                $this->requireSecret($secret, $authMethod),
            ),
            AuthMethod::OAUTH2_PASSWORD => $this->createOAuth2Password(
                $this->requireSecret($secret, $authMethod),
            ),
            AuthMethod::GLPI_REST_V1 => new GlpiRestV1AuthenticationStrategy(
                $authConfig->endpoint(),
                $this->requireSecret($secret, $authMethod),
                $this->destinationPolicy,
            ),
            // Authorization Code needs an interactive browser redirect
            // and user consent - structurally incompatible with an
            // unattended CronTask run, not merely "not implemented yet".
            AuthMethod::OAUTH2_AUTHORIZATION_CODE =>
                throw \GlpiPlugin\Uxconnector\Domain\Engine\Exception\HttpEngineException::unsupportedAuthMethod(
                    $authMethod
                ),
        };
    }

    /**
     * The vaulted secret for OAUTH2_CLIENT_CREDENTIALS is a small JSON
     * payload - {"token_url": "...", "client_id": "...",
     * "client_secret": "...", "scope": "..." (optional)} - composed by
     * AuthConfigController::create() from the form's dedicated OAuth2
     * fields (see its doc-block), rather than a single plain string
     * like every other AuthMethod. This keeps AuthConfig's own shape
     * (one Endpoint + one AuthMethod + one vaulted secret) unchanged
     * rather than adding OAuth2-specific columns used by no other
     * AuthMethod.
     */
    private function createOAuth2ClientCredentials(
        #[\SensitiveParameter] string $secret
    ): OAuth2ClientCredentialsAuthenticationStrategy {
        $config = json_decode($secret, true);

        if (
            !is_array($config)
             || !isset($config['token_url'], $config['client_id'], $config['client_secret'])
             || !is_string($config['token_url'])
             || !is_string($config['client_id'])
             || !is_string($config['client_secret'])
             || $config['token_url'] === ''
             || $config['client_id'] === ''
             || $config['client_secret'] === ''
        ) {
            throw \GlpiPlugin\Uxconnector\Domain\Engine\Exception\HttpEngineException::malformedOAuth2Configuration();
        }

        $scope = isset($config['scope']) && is_string($config['scope']) && $config['scope'] !== ''
            ? $config['scope']
            : null;

        return new OAuth2ClientCredentialsAuthenticationStrategy(
            $this->oauth2TokenClient,
            $config['token_url'],
            $config['client_id'],
            $config['client_secret'],
            $scope,
        );
    }

    private function createOAuth2Password(#[\SensitiveParameter] string $secret): OAuth2PasswordAuthenticationStrategy
    {
        $config = json_decode($secret, true);
        foreach (['token_url', 'client_id', 'client_secret', 'username', 'password'] as $key) {
            if (!is_array($config) || !isset($config[$key]) || !is_string($config[$key]) || $config[$key] === '') {
                throw \GlpiPlugin\Uxconnector\Domain\Engine\Exception\HttpEngineException
                    ::malformedOAuth2Configuration();
            }
        }
        $scope = isset($config['scope']) && is_string($config['scope']) && $config['scope'] !== '' ? $config['scope']
            : null;
        return new OAuth2PasswordAuthenticationStrategy(
            $this->oauth2TokenClient,
            $config['token_url'],
            $config['client_id'],
            $config['client_secret'],
            $config['username'],
            $config['password'],
            $scope,
        );
    }

    private function requireSecret(#[\SensitiveParameter] ?string $secret, AuthMethod $authMethod): string
    {
        if ($secret === null || $secret === '') {
            throw \GlpiPlugin\Uxconnector\Domain\Engine\Exception\HttpEngineException::missingCredential($authMethod);
        }

        return $secret;
    }
}
