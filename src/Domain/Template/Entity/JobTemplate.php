<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Template\Entity;

/** Declarative recipe that can be instantiated once into a concrete SyncJob. */
final readonly class JobTemplate
{
    /**
     * @param list<array{source:string,target:string,required?:bool,transform?:?string}> $fieldMappings
     * @param array{type:string,options?:array<string,mixed>} $pagination
     * @param list<TemplateParameter> $parameters
     * @param array<string, mixed> $outbound
     */
   public function __construct(
        public string $id,
        public string $version,
        public string $name,
        public string $description,
        public string $direction,
        public string $resourcePath,
        public string $collectionPath,
        public array $pagination,
        public string $targetItemtype,
        public string $matchField,
        public string $externalIdField,
        public array $fieldMappings,
        public array $parameters = [],
        public bool $available = true,
        public string $triggerType = 'polling',
        public ?string $externalObjectType = null,
        public array $outbound = []
    ) {
      foreach ([$id, $version, $name, $direction, $resourcePath, $collectionPath, $targetItemtype, $matchField, $externalIdField] as $value) {
         if (trim($value) === '') {
            throw new \InvalidArgumentException('JobTemplate mandatory values must not be empty.');
         }
      }
      if (!isset($pagination['type']) || !is_string($pagination['type'])) {
          throw new \InvalidArgumentException(sprintf('JobTemplate "%s" must declare pagination.type.', $id));
      }
      if ($fieldMappings === [] && $direction !== 'outbound') {
          throw new \InvalidArgumentException(sprintf('Inbound JobTemplate "%s" must declare at least one field mapping.', $id));
      }
      if (!in_array($this->triggerType, ['polling', 'webhook', 'glpi_event'], true)) {
          throw new \InvalidArgumentException(sprintf('JobTemplate "%s" declares an invalid trigger type.', $id));
      }
      if ($direction === 'outbound' && $this->outbound === []) {
          throw new \InvalidArgumentException(sprintf('Outbound JobTemplate "%s" must declare an outbound configuration.', $id));
      }
   }
}
