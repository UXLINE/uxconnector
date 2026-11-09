<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\GlpiRestV1;

use GlpiPlugin\Uxconnector\Application\Sync\Source\InboundResourcePathResolverInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;

final class GlpiRestV1TelecomLocationResourcePathResolver implements InboundResourcePathResolverInterface
{
    public const TEMPLATE_ID = 'glpi_rest_v1.indigo.telecom.locations.normalize';

    public function __construct(private readonly GlpiRestV1LocationSearchOptionCatalog $catalog) {}

    public function supports(SyncJob $syncJob): bool
    {
        return $syncJob->templateId() === self::TEMPLATE_ID;
    }

    public function resolve(AuthConfig $authConfig, SyncJob $syncJob, string $path): string
    {
        if (!$this->supports($syncJob)) {
            return $path;
        }
        $separator = str_contains($path, '?') ? '&' : '?';
        $pairs = [];
        foreach ($this->catalog->forcedDisplayIds($authConfig) as $index => $id) {
            $pairs[] = rawurlencode('forcedisplay[' . $index . ']') . '=' . rawurlencode((string) $id);
        }
        return $path . $separator . implode('&', $pairs);
    }
}
