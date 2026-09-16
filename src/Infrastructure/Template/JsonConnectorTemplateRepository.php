<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Template;

use GlpiPlugin\Uxconnector\Domain\Template\Entity\ConnectorTemplate;
use GlpiPlugin\Uxconnector\Domain\Template\Entity\JobTemplate;
use GlpiPlugin\Uxconnector\Domain\Template\Entity\TemplateParameter;
use GlpiPlugin\Uxconnector\Domain\Template\Repository\ConnectorTemplateRepositoryInterface;

/** Loads immutable connector manifests bundled with the plugin package. */
final class JsonConnectorTemplateRepository implements ConnectorTemplateRepositoryInterface
{
    /** @var list<ConnectorTemplate>|null */
   private ?array $cache = null;

   public function __construct(private readonly string $directory) {
   }

   public function findAll(): array {
      if ($this->cache !== null) {
          return $this->cache;
      }

       $files = glob(rtrim($this->directory, '/') . '/*/connector.json') ?: [];
       sort($files, SORT_STRING);
       $connectors = [];
       $seen = [];
       $seenJobs = [];

      foreach ($files as $file) {
          $connector = $this->loadFile($file);
         if (isset($seen[$connector->id])) {
             throw new \RuntimeException(sprintf('Duplicate connector template id "%s".', $connector->id));
         }
          $seen[$connector->id] = true;
         foreach ($connector->jobs as $job) {
            if (isset($seenJobs[$job->id])) {
                throw new \RuntimeException(sprintf('Duplicate job template id "%s".', $job->id));
            }
             $seenJobs[$job->id] = true;
         }
          $connectors[] = $connector;
      }

       return $this->cache = $connectors;
   }

   public function findById(string $id): ?ConnectorTemplate {
      foreach ($this->findAll() as $connector) {
         if ($connector->id === $id) {
            return $connector;
         }
      }
       return null;
   }

   /**
    * @return array{
    *     type: string,
    *     options?: array<string, mixed>
    * }
    */
   private function normalizePagination(
      mixed $raw,
      string $file
   ): array {
      if ($raw === null) {
         return ['type' => 'none'];
      }

      if (!is_array($raw)) {
         throw new \RuntimeException(
            sprintf(
               'Connector template "%s" contains an invalid pagination definition.',
               $file
            )
         );
      }

      $type = $raw['type'] ?? null;

      if (!is_string($type) || trim($type) === '') {
         throw new \RuntimeException(
            sprintf(
               'Connector template "%s" must declare pagination.type.',
               $file
            )
         );
      }

      if (!array_key_exists('options', $raw)) {
         return ['type' => $type];
      }

      if (!is_array($raw['options'])) {
         throw new \RuntimeException(
            sprintf(
               'Connector template "%s" contains invalid pagination options.',
               $file
            )
         );
      }

      $options = [];

      foreach ($raw['options'] as $key => $value) {
         if (!is_string($key)) {
            throw new \RuntimeException(
               sprintf(
                  'Connector template "%s" contains an invalid pagination option key.',
                  $file
               )
            );
         }

         $options[$key] = $value;
      }

      return [
         'type' => $type,
         'options' => $options,
      ];
   }

   /**
    * @return list<array{
    *     source: string,
    *     target: string,
    *     required?: bool,
    *     transform?: string|null
    * }>
    */
   private function normalizeFieldMappings(
      mixed $raw,
      string $file
   ): array {
      if ($raw === null) {
         return [];
      }

      if (!is_array($raw) || !array_is_list($raw)) {
         throw new \RuntimeException(
            sprintf(
               'Connector template "%s" contains invalid field mappings.',
               $file
            )
         );
      }

      $mappings = [];

      foreach ($raw as $mapping) {
         if (!is_array($mapping)) {
            throw new \RuntimeException(
               sprintf(
                  'Connector template "%s" contains an invalid field mapping.',
                  $file
               )
            );
         }

         $source = $mapping['source'] ?? null;
         $target = $mapping['target'] ?? null;

         if (!is_string($source) || trim($source) === '') {
            throw new \RuntimeException(
               sprintf(
                  'Connector template "%s" contains a field mapping without a valid source.',
                  $file
               )
            );
         }

         if (!is_string($target) || trim($target) === '') {
            throw new \RuntimeException(
               sprintf(
                  'Connector template "%s" contains a field mapping without a valid target.',
                  $file
               )
            );
         }

         $normalized = [
            'source' => $source,
            'target' => $target,
         ];

         if (array_key_exists('required', $mapping)) {
            if (!is_bool($mapping['required'])) {
               throw new \RuntimeException(
                  sprintf(
                     'Connector template "%s" contains a field mapping with an invalid required flag.',
                     $file
                  )
               );
            }

            $normalized['required'] = $mapping['required'];
         }

         if (array_key_exists('transform', $mapping)) {
            if ($mapping['transform'] !== null
               && !is_string($mapping['transform'])
            ) {
               throw new \RuntimeException(
                  sprintf(
                     'Connector template "%s" contains a field mapping with an invalid transform.',
                     $file
                  )
               );
            }

            $normalized['transform'] = $mapping['transform'];
         }

         $mappings[] = $normalized;
      }

      return $mappings;
   }
   private function loadFile(string $file): ConnectorTemplate {
       $json = file_get_contents($file);
      if ($json === false) {
          throw new \RuntimeException(sprintf('Unable to read connector template "%s".', $file));
      }

      try {
          $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
      } catch (\JsonException $exception) {
          throw new \RuntimeException(sprintf('Invalid connector template JSON "%s".', $file), previous: $exception);
      }
      if (!is_array($data)) {
          throw new \RuntimeException(sprintf('Connector template "%s" must contain a JSON object.', $file));
      }

       $jobs = [];
      foreach (($data['jobs'] ?? []) as $jobData) {
         if (!is_array($jobData)) {
             throw new \RuntimeException(sprintf('Connector template "%s" contains an invalid job.', $file));
         }

          $parameters = [];
         foreach (($jobData['parameters'] ?? []) as $parameterData) {
            if (!is_array($parameterData)) {
                throw new \RuntimeException('Template parameter must be a JSON object.');
            }
             $parameters[] = new TemplateParameter(
                 (string) ($parameterData['name'] ?? ''),
                 (string) ($parameterData['label'] ?? ''),
                 (bool) ($parameterData['required'] ?? true),
                 array_key_exists('default', $parameterData) && $parameterData['default'] !== null ? (string) $parameterData['default'] : null,
                 array_key_exists('description', $parameterData) && $parameterData['description'] !== null ? (string) $parameterData['description'] : null
             );
         }

          $mappings = $this->normalizeFieldMappings(
             $jobData['field_mappings'] ?? null,
             $file
          );
          $pagination = $this->normalizePagination(
             $jobData['pagination'] ?? null,
             $file
          );

          $jobs[] = new JobTemplate(
              id: (string) ($jobData['id'] ?? ''),
              version: (string) ($jobData['version'] ?? ($data['version'] ?? '1.0.0')),
              name: (string) ($jobData['name'] ?? ''),
              description: (string) ($jobData['description'] ?? ''),
              direction: (string) ($jobData['direction'] ?? ''),
              resourcePath: (string) ($jobData['resource_path'] ?? ''),
              collectionPath: (string) ($jobData['collection_path'] ?? '$'),
              pagination: $pagination,
              targetItemtype: (string) ($jobData['target_itemtype'] ?? ''),
              matchField: (string) ($jobData['match_field'] ?? ''),
              externalIdField: (string) ($jobData['external_id_field'] ?? ''),
              fieldMappings: $mappings,
              parameters: $parameters,
              available: (bool) ($jobData['available'] ?? true),
              triggerType: (string) ($jobData['trigger_type'] ?? 'polling'),
              externalObjectType: isset($jobData['external_object_type']) ? (string) $jobData['external_object_type'] : null,
              outbound: is_array($jobData['outbound'] ?? null) ? $jobData['outbound'] : []
          );
      }

       return new ConnectorTemplate(
           id: (string) ($data['id'] ?? ''),
           version: (string) ($data['version'] ?? ''),
           name: (string) ($data['name'] ?? ''),
           description: (string) ($data['description'] ?? ''),
           icon: (string) ($data['icon'] ?? 'ti ti-plug'),
           endpointHint: (string) ($data['endpoint_hint'] ?? ''),
           testPathHint: (string) ($data['test_path_hint'] ?? '/'),
           authMethods: array_values(array_map('strval', is_array($data['auth_methods'] ?? null) ? $data['auth_methods'] : [])),
           jobs: $jobs
       );
   }
}
