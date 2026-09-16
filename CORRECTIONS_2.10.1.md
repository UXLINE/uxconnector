# UXConnector 2.10.1 — Copilot Analytics Data Quality

## Purpose

2.10.1 consolidates the data-quality findings from the Lot 2 validation campaign before connecting UXConnector to Microsoft Copilot Studio.

## Corrections

- Ticket summary now exposes TTO/TTR sample sizes and coverage percentages.
- Historical `solve_delay_stat = 0` values no longer force a fake 0-second TTR when ticket timestamps prove a non-zero duration.
- Missing duration samples are returned as `null`, not `0 s`.
- Ticket trends now distinguish opening events from resolution events and include zero-activity buckets.
- Category analytics adds active backlog and resolution sample size.
- Group analytics exposes unique tickets with a group, tickets without a group, and multi-group overlap; group resolution averages include sample size.
- Technician analytics distinguishes assigned tickets from distinct tickets on which tasks were performed, adds average task duration, and supports `exclude_user_ids` without excluding any account by default.
- SLA analytics exposes deadline coverage, completed evaluable samples, historical breaches, currently breached open tickets, currently-within-deadline tickets and non-evaluable tickets.
- SLA breach rows are enriched with ticket title, status, entity, category, assigned groups and human-readable breach duration.
- Period comparison includes sample-size warnings and an explicit warning that `active_backlog` is a current-status cohort metric, not an end-of-period historical snapshot.
- Ticket context duration calculation uses timestamp fallback when imported historical delay-stat values are inconsistent; final solution selection is date-based.
- OpenAPI descriptions were updated so Copilot Studio can reason about the semantics and limits of each analytical tool.

## Compatibility

- GLPI 11
- Read-only Copilot Gateway
- No raw SQL endpoint
- No additional write capability
- Existing API-key authentication unchanged
