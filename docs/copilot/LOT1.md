# UXConnector Copilot Gateway — Lot 1

## Goal
Read-only Copilot Studio gateway backed by an existing **active UXConnector Connection** targeting the GLPI HL API.

## Prerequisite
Create/test/activate a UXConnector Connection whose endpoint is the GLPI HL API base URL and whose authentication is accepted by that API.

## Create a Copilot API client
From the GLPI root:

```bash
php plugins/uxconnector/tools/create-copilot-client.php "Copilot Studio" <AUTH_CONFIG_ID>
```

The command prints the API key once. Configure it in Copilot Studio as header `X-UXConnector-Copilot-Key`.

## Endpoints
GLPI-compatible machine endpoints:

- `GET /plugins/uxconnector/front/copilot-capabilities.php`
- `POST /plugins/uxconnector/front/copilot-search.php`
- `GET /plugins/uxconnector/front/copilot-context.php?id={id}`
- `GET /plugins/uxconnector/front/copilot-openapi.php`

The generated OpenAPI uses these endpoints directly and can be imported without a reverse-proxy rewrite.

## Security
- read-only V1;
- API keys are stored only as SHA-256 hashes;
- one Copilot client references one active UXConnector AuthConfig;
- upstream credentials remain in the existing encrypted UXConnector vault;
- GLPI firewall/session bypass is limited to the exact `front/copilot.php` machine endpoint;
- no SQL query endpoint is exposed to Copilot.

## Current mapping limitation
The supplied HL API inventory documents routes but not every build's filtering query syntax/response envelope. Lot 1 therefore normalizes several common envelopes (`data`, `items`, `results`, `records`) and applies conservative local filtering. After the first real HL API response is captured, tune `GlpiHlTicketNormalizer` and move supported filters upstream.
