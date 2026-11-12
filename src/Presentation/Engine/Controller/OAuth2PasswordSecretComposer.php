<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Presentation\Engine\Controller;

final class OAuth2PasswordSecretComposer
{
    /**
     * @param array<string, mixed> $request
     */
    public function compose(#[\SensitiveParameter] array $request): ?string
    {
        foreach (
            [
            'oauth2_password_token_url', 'oauth2_password_client_id', 'oauth2_password_client_secret',
            'oauth2_password_username', 'oauth2_password_password', 'oauth2_password_scope',
            ] as $field
        ) {
            if (isset($request[$field]) && !is_string($request[$field])) {
                throw new \InvalidArgumentException('OAuth2 form fields must be text values.');
            }
        }
        $fields = [
            'token_url' => trim((string) ($request['oauth2_password_token_url'] ?? '')),
            'client_id' => trim((string) ($request['oauth2_password_client_id'] ?? '')),
            'client_secret' => (string) ($request['oauth2_password_client_secret'] ?? ''),
            'username' => trim((string) ($request['oauth2_password_username'] ?? '')),
            'password' => (string) ($request['oauth2_password_password'] ?? ''),
            'scope' => trim((string) ($request['oauth2_password_scope'] ?? 'api')),
        ];
        foreach (['token_url', 'client_id', 'client_secret', 'username', 'password'] as $required) {
            if ($fields[$required] === '') {
                return null;
            }
        }
        try {
            return json_encode($fields, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Failed to encode the OAuth2 Password configuration to JSON.');
        }
    }
}
