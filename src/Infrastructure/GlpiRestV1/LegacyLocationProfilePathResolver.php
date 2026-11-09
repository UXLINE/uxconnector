<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\GlpiRestV1;

use GlpiPlugin\Uxconnector\Application\Sync\Source\InboundResourcePathResolverInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;

final class LegacyLocationProfilePathResolver implements InboundResourcePathResolverInterface
{
    public function __construct(private readonly LegacyLocationProfileCatalog $catalog) {}

    public function supports(SyncJob $job): bool
    {
        return in_array($job->templateId(), LegacyLocationMigrationProfiles::ids(), true);
    }

    public function resolve(AuthConfig $auth, SyncJob $job, string $path): string
    {
        $profile = (string) $job->templateId();
        $pairs = [];
        foreach ($this->catalog->forcedDisplayIds($auth, $profile) as $i => $id) {
            $pairs[] = rawurlencode('forcedisplay[' . $i . ']') . '=' . $id;
        }
        return $path . (str_contains($path, '?') ? '&' : '?') . implode('&', $pairs);
    }
}
