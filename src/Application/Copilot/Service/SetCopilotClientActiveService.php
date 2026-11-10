<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Copilot\Service;

use GlpiPlugin\Uxconnector\Domain\Copilot\Repository\CopilotClientRepositoryInterface;

final readonly class SetCopilotClientActiveService
{
    public function __construct(private CopilotClientRepositoryInterface $repository)
    {
    }
    public function execute(int $id, bool $active): void
    {
        $this->repository->setActive($id, $active);
    }
}
