<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Presentation\Engine\Controller;

final class GlpiRestV1SecretComposer
{
    public function compose(array $request): ?string
    {
        $appToken = trim((string) ($request['glpi_rest_app_token'] ?? ''));
        $userToken = trim((string) ($request['glpi_rest_user_token'] ?? ''));
        if ($appToken === '' || $userToken === '') {
            return null;
        }
        return json_encode([
            'app_token' => $appToken,
            'user_token' => $userToken,
            'init_path' => '/initSession',
            'close_path' => '/killSession',
            'session_field' => 'session_token',
            'timeout' => 60,
            'retry_count' => 4,
            'renew_session' => true,
            'close_session' => true,
        ], JSON_THROW_ON_ERROR);
    }
}
