<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Copilot\Service;

use GlpiPlugin\Uxconnector\Domain\Copilot\Repository\CopilotClientRepositoryInterface;

final readonly class DeleteCopilotClientService
{
    public function __construct(private CopilotClientRepositoryInterface $repository) {}
    public function execute(int $id): void
    {
        $this->repository->delete($id);
    }
}
