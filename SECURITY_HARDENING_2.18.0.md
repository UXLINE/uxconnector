# UXConnector 2.18.0 — Security hardening without Zendesk regression

This release hardens the generic connector runtime while preserving the high-volume Zendesk migration workflow (users, groups, entities, ITIL categories, tickets, follow-ups/comments and documents/attachments).

## Inbound webhooks

The historical `X-UXConnector-Webhook-Key` header remains supported so existing header-based integrations continue to work.

Passing the webhook key in the query string (`?key=...`) is disabled by default because URLs are commonly copied into reverse-proxy, web-server, APM and browser logs. A temporary compatibility switch is available during migration:

```text
UXCONNECTOR_ALLOW_LEGACY_WEBHOOK_QUERY_KEY=1
```

A signed mode can be enabled without changing the persisted job model:

```text
UXCONNECTOR_REQUIRE_WEBHOOK_HMAC=1
UXCONNECTOR_WEBHOOK_MAX_CLOCK_SKEW=300
```

Signed requests must include:

```text
X-UXConnector-Webhook-Key: <64 hex chars>
X-UXConnector-Timestamp: <unix timestamp>
X-UXConnector-Signature: sha256=<HMAC hex>
```

The signature input is:

```text
<timestamp>.<exact raw HTTP body>
```

using the webhook key as the HMAC-SHA256 secret. The timestamp freshness window limits replay exposure. Provider-specific nonce/idempotency protection can still be added later for strict distributed replay prevention.

## Destination allowlist

The existing SSRF protections remain in place. Administrators can additionally switch UXConnector to an explicit destination allowlist without changing connector code:

```text
UXCONNECTOR_ALLOWED_HOSTS=acme.zendesk.com,*.zdusercontent.com,glpi.internal.example
```

If this variable is empty or absent, behavior remains unchanged. Wildcards apply only to subdomains (`*.example.com` does not implicitly authorize `example.com`).

For Zendesk attachment migrations, remember to authorize both the Zendesk tenant host and any legitimate Zendesk/CDN hosts used by attachment URLs.

## Built-in Zendesk template target locking

The generic writer still supports `User`, `Group`, `Entity`, `ITILCategory`, `Ticket`, `ITILFollowup` and `Document`. The security change does **not** remove those capabilities. Instead, every built-in Zendesk template id is locked to the target type it was designed for. For example, `zendesk.tickets.nonclosed.inbound` can only target `Ticket`, while `zendesk.users.bootstrap` can only target `User`.

Ad-hoc jobs keep their existing generic capabilities and existing Zendesk migrations continue to populate all required GLPI tables.

## Volume safety limits

UXConnector still processes inbound jobs page by page and therefore keeps bounded memory usage for large migrations. A safety ceiling now prevents an accidentally misconfigured endpoint from writing an unbounded number of GLPI objects in one execution.

Defaults:

```text
UXCONNECTOR_MAX_RECORDS_PER_RUN=50000
UXCONNECTOR_MAX_RECORDS_PER_WEBHOOK=50000
```

`0` means unlimited. Intentional migrations larger than 50,000 records can raise the value explicitly. A 20,000-ticket Zendesk import remains below the default ceiling.

The existing 1,000-page safety ceiling remains unchanged.

## Zendesk volume regression harness

`tools/zendesk-volume-test.php` now sets the Zendesk ticket template id explicitly so the synthetic run exercises the same template-sensitive processing path expected by current runtime code.
