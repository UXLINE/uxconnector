# UXConnector 2.10.0 — Copilot Analytics Lot 2

## Navigation

The AI Agents page is no longer reachable only by manually typing its URL. The Connections screen now exposes an **AI Agents / Copilot** button and a compact UXConnector navigation bar linking Connections, AI Agents, Connector catalog and Interactions according to the current profile rights.

## Ticket context normalization

`getTicketContext` now resolves task/followup/solution/validation actors to user names when possible, exposes all assigned groups, returns human-readable TTO/TTR and cumulative task work durations, identifies a `final_solution`, and produces a canonical chronological timeline.

## Analytics tools

The local GLPI provider now exposes read-only endpoints for:

- ticket volume / summary;
- ticket trends by day, week or month;
- category analysis;
- TTO/TTR SLA compliance and breach details when GLPI deadlines are parseable;
- support-group workload/backlog;
- technician workload and cumulative task action time;
- comparison between two periods.

The corresponding operations are documented in the generated OpenAPI document for Microsoft Copilot Studio.

## Security

All analytical endpoints remain stateless machine endpoints protected by `X-UXConnector-Copilot-Key`. No SQL endpoint, write action or generic GLPI proxy is exposed.
