<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Copilot\Semantic\Service;

use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\Port\SemanticTicketSourceInterface;
use GlpiPlugin\Uxconnector\Application\Copilot\Semantic\Adapter\PrecomputedSemanticClassificationProvider;
use GlpiPlugin\Uxconnector\Application\Copilot\Semantic\Adapter\PreparedSemanticTicketSource;

final readonly class FinalizeSemanticTicketClassificationService
{
    public function __construct(private SemanticTicketSourceInterface $source)
    {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function execute(array $payload): array
    {
        $version = trim((string) ($payload['classifier_version'] ?? ''));
        if ($version !== PowerPlatformSemanticPolicy::CLASSIFIER_VERSION) {
            throw new \InvalidArgumentException(
                'classifier_version must be "' . PowerPlatformSemanticPolicy::CLASSIFIER_VERSION . '".',
            );
        }

        $fingerprint = strtolower(trim((string) ($payload['scope_fingerprint'] ?? '')));
        if (
            strlen($fingerprint) !== 64
             || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1
        ) {
            throw new \InvalidArgumentException('scope_fingerprint must be the SHA-256 returned by prepare.');
        }

        $criteria = SemanticPreparationFingerprint::normalizeCriteria($payload);
        $sourceResult = $this->source->fetch($criteria);
        $currentFingerprint = SemanticPreparationFingerprint::calculate($criteria, $sourceResult);

        if (!hash_equals($currentFingerprint, $fingerprint)) {
            throw new \RuntimeException(
                'Semantic preparation is stale or does not match the current ticket scope. Run prepare again.',
            );
        }

        $rows = $payload['classifications'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new \InvalidArgumentException('classifications must be a JSON array.');
        }

        $analysis = new AnalyzeSemanticTicketClassificationService(
            new PreparedSemanticTicketSource($sourceResult),
            new PrecomputedSemanticClassificationProvider(
                $rows,
                PowerPlatformSemanticPolicy::CONFIDENCE_THRESHOLD,
            ),
            PowerPlatformSemanticPolicy::ephemeralConfig(),
            PowerPlatformSemanticPolicy::PROVIDER_CONTRACT,
        );

        $result = $analysis->execute($criteria);
        $result['engine']['execution_mode'] = 'power_platform_orchestrated';
        $result['engine']['scope_fingerprint'] = $currentFingerprint;

        return $result;
    }
}
