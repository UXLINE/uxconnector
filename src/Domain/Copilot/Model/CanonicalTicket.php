<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Copilot\Model;

final readonly class CanonicalTicket
{
       /**
    * @param list<string>               $assignedUsers
    * @param list<array<string, mixed>> $assets
    * @param array<string, mixed>       $raw
    */
   public function __construct(
        public int $id,
        public string $reference,
        public string $title,
        public string $description,
        public ?string $type,
        public ?string $status,
        public ?string $priority,
        public ?string $urgency,
        public ?string $impact,
        public ?string $category,
        public ?string $entity,
        public ?string $requester,
        public ?string $assignedGroup,
        public array $assignedUsers,
        public array $assets,
        public ?string $createdAt,
        public ?string $resolvedAt,
        public ?string $closedAt,
        public ?string $timeToOwn,
        public ?string $timeToResolve,
        public array $raw = []
    ) {
   }

    /** @return array<string,mixed> */
   public function toArray(bool $includeRaw = false): array {
       $data = [
           'id' => $this->id,
           'reference' => $this->reference,
           'title' => $this->title,
           'description' => $this->description,
           'type' => $this->type,
           'status' => $this->status,
           'priority' => $this->priority,
           'urgency' => $this->urgency,
           'impact' => $this->impact,
           'category' => $this->category,
           'entity' => $this->entity,
           'requester' => $this->requester,
           'assigned_group' => $this->assignedGroup,
           'assigned_users' => $this->assignedUsers,
           'assets' => $this->assets,
           'created_at' => $this->createdAt,
           'resolved_at' => $this->resolvedAt,
           'closed_at' => $this->closedAt,
           'time_to_own' => $this->timeToOwn,
           'time_to_resolve' => $this->timeToResolve,
       ];

       if ($includeRaw) {
           $data['raw'] = $this->raw;
       }

       return $data;
   }
}
