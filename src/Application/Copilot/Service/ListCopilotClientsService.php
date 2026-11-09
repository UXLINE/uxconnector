<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Copilot\Service;

use GlpiPlugin\Uxconnector\Domain\Copilot\Repository\CopilotClientRepositoryInterface;

final readonly class ListCopilotClientsService
{
    public function __construct(private CopilotClientRepositoryInterface $repository) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function execute(): array
    {
        return $this->repository->all();
    }
}
