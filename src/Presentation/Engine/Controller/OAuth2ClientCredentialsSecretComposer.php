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

namespace GlpiPlugin\Uxconnector\Presentation\Engine\Controller;

/**
 * Composes the JSON payload AuthenticationStrategyFactory expects as
 * the vaulted "secret" for AuthMethod::OAUTH2_CLIENT_CREDENTIALS, from
 * the creation form's four dedicated OAuth2 fields (token URL, client
 * ID, client secret, optional scope).
 *
 * Kept as a standalone, testable class rather than inlined in
 * AuthConfigController::create() — same rationale as
 * FieldMappingRequestParser and EntityAssignmentStrategyRequestParser
 * in the Sync bounded context.
 */
final class OAuth2ClientCredentialsSecretComposer
{
    /**
     * @param array<string, mixed> $request Typically $_POST.
     *
     * @return string|null The composed JSON payload, or null if the
     *         three required fields (token URL, client ID, client
     *         secret) were not all supplied.
     */
   public function compose(array $request): ?string {
       $tokenUrl = trim((string) ($request['oauth2_token_url'] ?? ''));
       $clientId = trim((string) ($request['oauth2_client_id'] ?? ''));
       $clientSecret = trim((string) ($request['oauth2_client_secret'] ?? ''));
       $scope = trim((string) ($request['oauth2_scope'] ?? ''));

      if ($tokenUrl === '' || $clientId === '' || $clientSecret === '') {
          return null;
      }

       $payload = [
           'token_url' => $tokenUrl,
           'client_id' => $clientId,
           'client_secret' => $clientSecret,
       ];

       if ($scope !== '') {
           $payload['scope'] = $scope;
       }

       try {
           return json_encode($payload, JSON_THROW_ON_ERROR);
       } catch (\JsonException $exception) {
           throw new \RuntimeException(
               'Failed to encode the OAuth2 Client Credentials configuration to JSON.',
               previous: $exception
           );
       }
   }
}
