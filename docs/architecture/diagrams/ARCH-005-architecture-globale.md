# ARCH-005 — Architecture globale UXConnector (squelette v2.0.0)

```mermaid
flowchart TD
    subgraph Presentation
        API[Api/Controller]
        CTRL[Controller]
        HOOK[Hook]
        FORM[Form]
        VIEW[View]
    end

    subgraph Application
        direction TB
        AppEngine[Engine: UseCase / Command / DTO]
        AppSync[Sync: UseCase / Command / DTO]
    end

    subgraph Domain
        direction TB
        DomEngine[Engine: Entity / ValueObject / Repository iface]
        DomSync[Sync: Entity / ValueObject / Repository iface]
    end

    subgraph Infrastructure
        direction TB
        InfraEngine[Engine: Persistence / Security]
        InfraSync[Sync: Persistence / Cron]
    end

    Presentation --> Application
    Application --> Domain
    Infrastructure --> Domain

    AppEngine --> DomEngine
    AppSync --> DomSync
    InfraEngine --> DomEngine
    InfraSync --> DomSync
```

## Dépendances autorisées

| Couche         | Dépend de   |
| -------------- | ----------- |
| Presentation   | Application |
| Application    | Domain      |
| Infrastructure | Domain      |
| Domain         | Aucune      |

## Dépendances interdites

- `Domain → Infrastructure`
- `Domain → Presentation`
- `Domain → GLPI`
- `Application → Presentation`
- `Infrastructure → Presentation`

Voir [`ADR-001`](../adr/ADR-001-squelette-plugin.md).
