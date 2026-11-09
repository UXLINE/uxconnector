<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Zendesk;

/** Outcome of reconciling one logical Zendesk topic with its GLPI ITIL category. */
final readonly class ZendeskTopicCategoryOutcome
{
    private const ALLOWED_ACTIONS = ['create', 'update', 'skip'];

    public function __construct(
        public int $categoryId,
        public string $action
    ) {
        if ($categoryId <= 0) {
            throw new \InvalidArgumentException('Category identifier must be positive.');
        }
        if (!in_array($action, self::ALLOWED_ACTIONS, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported category reconciliation action "%s".', $action));
        }
    }
}
