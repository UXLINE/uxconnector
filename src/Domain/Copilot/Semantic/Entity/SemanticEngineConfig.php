<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\Entity;

use DateTimeImmutable;
use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\Enum\SemanticEngineConfigStatus;
use GlpiPlugin\Uxconnector\Domain\Copilot\Semantic\ValueObject\SemanticEngineConfigId;

final class SemanticEngineConfig
{
   private ?SemanticEngineConfigId $id = null;

   private function __construct(
        private string $name,
        private int $authConfigReference,
        private string $model,
        private string $inferencePath,
        private int $batchSize,
        private float $confidenceThreshold,
        private float $temperature,
        private string $classifierVersion,
        private SemanticEngineConfigStatus $status,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt
    ) {
       $this->assertValid();
   }

   public static function create(
        string $name,
        int $authConfigReference,
        string $model,
        string $inferencePath,
        int $batchSize = 20,
        float $confidenceThreshold = 0.7,
        float $temperature = 0.0,
        string $classifierVersion = '1.0.0'
    ): self {
       $now = new DateTimeImmutable();
       return new self(
           $name,
           $authConfigReference,
           $model,
           $inferencePath,
           $batchSize,
           $confidenceThreshold,
           $temperature,
           $classifierVersion,
           SemanticEngineConfigStatus::DRAFT,
           $now,
           $now
       );
   }

   public static function reconstitute(
        SemanticEngineConfigId $id,
        string $name,
        int $authConfigReference,
        string $model,
        string $inferencePath,
        int $batchSize,
        float $confidenceThreshold,
        float $temperature,
        string $classifierVersion,
        SemanticEngineConfigStatus $status,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt
    ): self {
       $config = new self(
           $name,
           $authConfigReference,
           $model,
           $inferencePath,
           $batchSize,
           $confidenceThreshold,
           $temperature,
           $classifierVersion,
           $status,
           $createdAt,
           $updatedAt
       );
       $config->id = $id;
       return $config;
   }

   public function assignId(SemanticEngineConfigId $id): void {
      if ($this->id !== null) {
          throw new \LogicException('SemanticEngineConfig id cannot be assigned twice.');
      }
       $this->id = $id;
   }

   public function reconfigure(
        string $name,
        int $authConfigReference,
        string $model,
        string $inferencePath,
        int $batchSize,
        float $confidenceThreshold,
        float $temperature,
        string $classifierVersion
    ): void {
       $this->name = $name;
       $this->authConfigReference = $authConfigReference;
       $this->model = $model;
       $this->inferencePath = $inferencePath;
       $this->batchSize = $batchSize;
       $this->confidenceThreshold = $confidenceThreshold;
       $this->temperature = $temperature;
       $this->classifierVersion = $classifierVersion;
       $this->status = SemanticEngineConfigStatus::DRAFT;
       $this->assertValid();
       $this->touch();
   }

   public function activate(): void {
       $this->status = SemanticEngineConfigStatus::ACTIVE;
       $this->touch();
   }

   public function disable(): void {
       $this->status = SemanticEngineConfigStatus::DISABLED;
       $this->touch();
   }

   public function markError(): void {
       $this->status = SemanticEngineConfigStatus::ERROR;
       $this->touch();
   }

   public function id(): ?SemanticEngineConfigId {
      return $this->id; }
   public function name(): string {
      return $this->name; }
   public function authConfigReference(): int {
      return $this->authConfigReference; }
   public function model(): string {
      return $this->model; }
   public function inferencePath(): string {
      return $this->inferencePath; }
   public function batchSize(): int {
      return $this->batchSize; }
   public function confidenceThreshold(): float {
      return $this->confidenceThreshold; }
   public function temperature(): float {
      return $this->temperature; }
   public function classifierVersion(): string {
      return $this->classifierVersion; }
   public function status(): SemanticEngineConfigStatus {
      return $this->status; }
   public function isUsable(): bool {
      return $this->status->isUsable(); }
   public function createdAt(): DateTimeImmutable {
      return $this->createdAt; }
   public function updatedAt(): DateTimeImmutable {
      return $this->updatedAt; }

   private function assertValid(): void {
       $this->name = trim($this->name);
       $this->model = trim($this->model);
       $this->inferencePath = trim($this->inferencePath);
       $this->classifierVersion = trim($this->classifierVersion);

      if ($this->name === '') {
          throw new \InvalidArgumentException('Semantic engine configuration name must not be empty.');
      }
      if ($this->authConfigReference <= 0) {
          throw new \InvalidArgumentException('Semantic engine auth_config_reference must be positive.');
      }
      if ($this->model === '') {
          throw new \InvalidArgumentException('Semantic engine model must not be empty.');
      }
      if ($this->inferencePath === ''
           || $this->inferencePath[0] !== '/'
           || preg_match('/[\r\n\0]/', $this->inferencePath) === 1
       ) {
          throw new \InvalidArgumentException('Semantic engine inference_path must be a safe absolute API path.');
      }
      if ($this->batchSize < 1 || $this->batchSize > 100) {
          throw new \InvalidArgumentException('Semantic engine batch_size must be between 1 and 100.');
      }
      if ($this->confidenceThreshold < 0.0 || $this->confidenceThreshold > 1.0) {
          throw new \InvalidArgumentException('Semantic engine confidence_threshold must be between 0 and 1.');
      }
      if ($this->temperature < 0.0 || $this->temperature > 2.0) {
          throw new \InvalidArgumentException('Semantic engine temperature must be between 0 and 2.');
      }
      if ($this->classifierVersion === ''
           || strlen($this->classifierVersion) > 32
           || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $this->classifierVersion) !== 1
       ) {
          throw new \InvalidArgumentException('Semantic engine classifier_version is invalid.');
      }
   }

   private function touch(): void {
       $this->updatedAt = new DateTimeImmutable();
   }
}
