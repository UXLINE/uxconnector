# UXConnector 2.10.2 — Copilot Analytics Semantics

## Why this patch exists

Validation of the July/August comparison showed that `time_to_resolve_coverage_percent` was mathematically calculated against all tickets opened in the period. That value was therefore closer to a resolution ratio than to a data-quality coverage ratio. In addition, a current month can be compared against a completed month even though raw totals are not directly comparable.

## Corrections

- `time_to_resolve_coverage_percent` = evaluable TTR samples / currently solved-or-closed tickets.
- `resolution_rate_percent` = currently solved-or-closed tickets / all tickets opened in the period.
- TTR samples are collected only from tickets currently in solved/closed status.
- Each summary now exposes `period_observation` with `as_of_date`, `requested_days`, `observed_days`, `observed_through` and `is_partial`.
- `comparePeriods` warns when at least one period is incomplete or extends beyond today.
- `comparePeriods` exposes `normalized_daily_rates` based on observed days.
- Existing raw totals and deltas are preserved for backward compatibility.

## Compatibility

- GLPI 11
- Read-only Copilot Gateway
- No database schema change
- Existing API-key authentication unchanged
