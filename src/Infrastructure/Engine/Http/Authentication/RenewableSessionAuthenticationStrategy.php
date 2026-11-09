<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Engine\Http\Authentication;

use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpResponse;

interface RenewableSessionAuthenticationStrategy extends AuthenticationStrategy
{
    public function responseRequiresRenewal(HttpResponse $response): bool;
    public function invalidateSession(): void;
    public function closeSession(): void;
}
