# Copilot Gateway — Lot 2 Analytics

UXConnector 2.10.0 extends the read-only Copilot Gateway with compact analytical tools. The LLM receives aggregated ITSM results instead of large raw ticket exports.

## Endpoints

- `POST /plugins/uxconnector/front/copilot-ticket-summary.php`
- `POST /plugins/uxconnector/front/copilot-ticket-trends.php`
- `POST /plugins/uxconnector/front/copilot-categories.php`
- `POST /plugins/uxconnector/front/copilot-sla.php`
- `POST /plugins/uxconnector/front/copilot-groups.php`
- `POST /plugins/uxconnector/front/copilot-technicians.php`
- `POST /plugins/uxconnector/front/copilot-compare.php`

All require the `X-UXConnector-Copilot-Key` header.

## Important SLA behavior

Compliance rates only include tickets where GLPI exposes a deadline in a date/time form that can be evaluated. Tickets without an evaluable deadline are excluded rather than guessed as compliant or breached.
