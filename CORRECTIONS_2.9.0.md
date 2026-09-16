# UXConnector 2.9.0 — Copilot Gateway Lot 1

## Added
- New `Copilot` bounded context following the existing Clean Architecture.
- Canonical ITSM ticket model and `ItsmDataProviderInterface`.
- GLPI HL API provider using the existing UXConnector AuthConfig + GenericHttpEngine + credential vault.
- Read-only Copilot endpoints for capabilities, ticket search and complete ticket context.
- API-key authentication with SHA-256-only persistence.
- CLI utility to create Copilot clients.
- OpenAPI 3.0 document directly importable in Copilot Studio / Custom Connector.
- Tool catalog preparing the future analytics tools.
- GLPI stateless/firewall registration limited to Copilot machine endpoints.

## Security
- No arbitrary SQL or arbitrary GLPI route proxy is exposed.
- Copilot V1 is read-only.
- Upstream GLPI API credentials stay in the existing encrypted credential vault.
- Copilot keys are displayed once and only their SHA-256 hashes are persisted.

## Known limitation
The provided HL API route inventory does not fully document the filtering query syntax/response envelopes for the target deployment. Ticket list filtering is therefore conservative/local in Lot 1 and the normalizer is intentionally tolerant. Capture the first real `/Assistance/Ticket` JSON response before optimizing server-side filters.
