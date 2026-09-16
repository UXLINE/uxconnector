<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Template\Repository;

use GlpiPlugin\Uxconnector\Domain\Template\Entity\ConnectorTemplate;

interface ConnectorTemplateRepositoryInterface
{
    /** @return list<ConnectorTemplate> */
   public function findAll(): array;

   public function findById(string $id): ?ConnectorTemplate;
}
