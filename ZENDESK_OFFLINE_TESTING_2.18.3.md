# UXConnector 2.18.3 - Zendesk offline regression suite

This release adds an offline regression suite so Zendesk migration behaviour can be validated even when no live Zendesk tenant is available.

## Run

From the plugin directory, without `GLPI_ROOT`:

```bash
php vendor/bin/phpunit -c phpunit.xml.dist --testsuite ZendeskOffline
```

The suite uses plugin-local GLPI test doubles and never connects to a live Zendesk tenant or a GLPI database.

## Covered behaviour

The fixture represents a Zendesk ticket with:

- requester;
- assignee;
- assigned group;
- organization;
- custom `Sujet` tagger value mapped to an ITIL category hierarchy;
- initial ticket comment;
- public followup;
- private/internal followup;
- attachment on the initial ticket comment;
- inline image on a later comment.

The assertions cover:

- entity assignment;
- creation of `IT :: Accès` as parent + leaf GLPI ITIL categories and assignment of the leaf category to the ticket;
- requester/assignee/group relationships;
- no duplicate followup for the Zendesk initial description;
- public/private followup preservation;
- attachment reconciliation into GLPI documents;
- inline image conversion to a local GLPI document reference;
- second-pass replay idempotence;
- the 2.18.1 regression fix: a missing organization reconciliation link keeps the record retryable but does not prevent category, comments or attachments from being imported.

## Safety

If a real GLPI runtime is already booted, the offline suite skips itself rather than writing to the GLPI database. It is intended to run without `GLPI_ROOT`.
