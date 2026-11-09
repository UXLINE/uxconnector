<?php

/**
 * -------------------------------------------------------------------------
 * UXConnector plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * @copyright HELPLINE
 * @license   GPL-3.0-or-later
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Engine\Entity;

use DateTimeImmutable;
use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthConfigStatus;
use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\Event\AuthConfigCreatedEvent;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigName;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\TestPath;
use GlpiPlugin\Uxconnector\Domain\Shared\Event\DomainEvent;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;

/**
 * AuthConfig is the aggregate root of the Engine bounded context.
 *
 * It represents a reusable, named authentication configuration
 * (endpoint + method) that the GenericHttpEngine uses to reach a
 * third-party API. The actual secret material (API key, OAuth client
 * secret, password, ...) never lives on this aggregate: it is stored
 * separately by the CredentialVault (GLPI native encryption / glpicrypt.key)
 * and only ever referenced here, never exposed (RULE-CONNECTOR-005 :
 * no GLPI dependency, and no secret ever crosses the Domain boundary
 * in clear text).
 *
 * RULE-ENTITY-001 to 005 apply: properties stay private, invariants
 * are enforced in this class, state changes go through explicit
 * business methods, no SQL, and the aggregate has real behaviour.
 */
final class AuthConfig
{
    /**
     * @var list<DomainEvent>
     */
    private array $events = [];

    private ?AuthConfigId $id = null;

    private AuthConfigStatus $status;

    private DateTimeImmutable $createdAt;

    private DateTimeImmutable $updatedAt;

    private TestPath $testPath;

    private function __construct(
        private AuthConfigName $name,
        private Endpoint $endpoint,
        private AuthMethod $authMethod,
        ?TestPath $testPath = null,
        private string $connectorId = 'custom'
    ) {
        $this->testPath = $testPath ?? new TestPath('/');
        $this->status = AuthConfigStatus::DRAFT;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    /**
     * Creates a brand new AuthConfig in DRAFT status.
     *
     * The identifier is deliberately not known yet: it is assigned by
     * the Repository once the aggregate has been persisted (see
     * assignId()).
     */
    public static function create(
        AuthConfigName $name,
        Endpoint $endpoint,
        AuthMethod $authMethod,
        ?TestPath $testPath = null,
        string $connectorId = 'custom'
    ): self {
        $authConfig = new self($name, $endpoint, $authMethod, $testPath, self::normalizeConnectorId($connectorId));

        $authConfig->record(
            new AuthConfigCreatedEvent($name, $authMethod),
        );

        return $authConfig;
    }

    /**
     * Rehydrates an existing AuthConfig from persistence. This named
     * constructor never records Domain Events: nothing "happens" when
     * the Repository loads an aggregate that already exists.
     */
    public static function reconstitute(
        AuthConfigId $id,
        AuthConfigName $name,
        Endpoint $endpoint,
        AuthMethod $authMethod,
        AuthConfigStatus $status,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
        ?TestPath $testPath = null,
        string $connectorId = 'custom'
    ): self {
        $authConfig = new self($name, $endpoint, $authMethod, $testPath, self::normalizeConnectorId($connectorId));
        $authConfig->id = $id;
        $authConfig->status = $status;
        $authConfig->createdAt = $createdAt;
        $authConfig->updatedAt = $updatedAt;

        return $authConfig;
    }

    /**
     * Assigns the identifier generated at persistence time. May only
     * be called once: identity is stable for the lifetime of the
     * aggregate (RULE-CONNECTOR-003).
     */
    public function assignId(AuthConfigId $id): void
    {
        if ($this->id !== null) {
            throw \GlpiPlugin\Uxconnector\Domain\Engine\Exception\InvalidAuthConfigStateException::cannotAssignIdTwice();
        }

        $this->id = $id;
    }

    public function rename(AuthConfigName $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    /**
     * Changes the endpoint. Since the previous credentials/handshake
     * may no longer be valid against a different endpoint, the
     * aggregate is implicitly moved back to DRAFT and must be
     * re-tested before it can be activated again.
     */
    public function changeEndpoint(Endpoint $endpoint): void
    {
        $this->endpoint = $endpoint;
        $this->status = AuthConfigStatus::DRAFT;
        $this->touch();
    }

    public function changeAuthMethod(AuthMethod $authMethod): void
    {
        $this->authMethod = $authMethod;
        $this->status = AuthConfigStatus::DRAFT;
        $this->touch();
    }

    /**
     * Changes the resource used to validate the connection. A different test
     * target invalidates the previous validation result.
     */
    public function changeTestPath(TestPath $testPath): void
    {
        if ($this->testPath->value() === $testPath->value()) {
            return;
        }

        $this->testPath = $testPath;
        $this->status = AuthConfigStatus::DRAFT;
        $this->touch();
    }

    /**
     * Explicitly invalidates a previous validation without changing transport
     * metadata, for example when the credential is replaced.
     */
    public function invalidateValidation(): void
    {
        $this->status = AuthConfigStatus::DRAFT;
        $this->touch();
    }

    /**
     * Marks the aggregate as currently being validated against the
     * real endpoint (a connectivity/credentials check is in flight).
     */
    public function markTesting(): void
    {
        $this->status = AuthConfigStatus::TESTING;
        $this->touch();
    }

    /**
     * Activates the AuthConfig, making it usable by the
     * GenericHttpEngine. Only reachable from TESTING: an AuthConfig
     * must have been validated at least once before it can serve
     * live traffic.
     */
    public function activate(): void
    {
        if ($this->status !== AuthConfigStatus::TESTING) {
            throw \GlpiPlugin\Uxconnector\Domain\Engine\Exception\InvalidAuthConfigStateException::cannotActivateFromStatus(
                $this->status,
            );
        }

        $this->status = AuthConfigStatus::ACTIVE;
        $this->touch();
    }

    public function disable(): void
    {
        $this->status = AuthConfigStatus::DISABLED;
        $this->touch();
    }

    public function markError(): void
    {
        $this->status = AuthConfigStatus::ERROR;
        $this->touch();
    }

    public function isUsable(): bool
    {
        return $this->status->isUsable();
    }

    public function id(): ?AuthConfigId
    {
        return $this->id;
    }

    public function name(): AuthConfigName
    {
        return $this->name;
    }

    public function endpoint(): Endpoint
    {
        return $this->endpoint;
    }

    public function authMethod(): AuthMethod
    {
        return $this->authMethod;
    }

    public function testPath(): TestPath
    {
        return $this->testPath;
    }

    public function status(): AuthConfigStatus
    {
        return $this->status;
    }

    public function connectorId(): string
    {
        return $this->connectorId;
    }

    /**
     * Associates the connection with a catalogue definition. This changes only
     * its product type/available interaction templates; credentials and endpoint
     * remain untouched.
     */
    public function changeConnectorId(string $connectorId): void
    {
        $normalized = self::normalizeConnectorId($connectorId);
        if ($this->connectorId === $normalized) {
            return;
        }

        $this->connectorId = $normalized;
        $this->status = AuthConfigStatus::DRAFT;
        $this->touch();
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Returns and clears the Domain Events recorded since the last
     * call. The Repository/Application Service is responsible for
     * dispatching them only after successful persistence.
     *
     * @return list<DomainEvent>
     */
    public function releaseEvents(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }

    private function record(DomainEvent $event): void
    {
        $this->events[] = $event;
    }


    private static function normalizeConnectorId(string $connectorId): string
    {
        $connectorId = strtolower(trim($connectorId));
        if ($connectorId === '') {
            return 'custom';
        }
        if (preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $connectorId) !== 1) {
            throw new \InvalidArgumentException('Invalid connector identifier.');
        }
        return $connectorId;
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
