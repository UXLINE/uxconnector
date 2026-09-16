<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Engine\Service;

use GlpiPlugin\Uxconnector\Application\Engine\DTO\AuthConfigDTO;
use GlpiPlugin\Uxconnector\Domain\Engine\Repository\AuthConfigRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;

final class GetAuthConfigService
{
   public function __construct(private readonly AuthConfigRepositoryInterface $repository) {
   }

   public function execute(AuthConfigId $id): AuthConfigDTO {
       $connection = $this->repository->findById($id);
      if ($connection === null) {
          throw \GlpiPlugin\Uxconnector\Application\Engine\Exception\AuthConfigNotFoundException::forId($id);
      }

       return AuthConfigDTO::fromEntity($connection);
   }
}
