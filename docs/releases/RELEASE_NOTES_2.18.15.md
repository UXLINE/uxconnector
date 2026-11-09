# UXConnector 2.18.15 - Zendesk Sujet catalog bootstrap

## Scope

- The existing `zendesk.itilcategories.bootstrap` **now reads the Zendesk ticket-field definitions and the selected Sujet tagger options**, with bounded pagination. The bootstrap **never reads tickets**, including for already-created jobs whose persisted resource path still references Search Export.
- The stable `topic__...` tag is the ObjectLink external ID. Human-readable labels containing `::` become native GLPI hierarchical ITILCategory nodes (displayed by GLPI with `>`).
- The Zendesk ticket initial-import and delta jobs are unchanged. Ticket post-processing resolves their category from the stable technical topic key, first from `custom_fields`, then from `tags` only if exactly one known topic matches. Unknown categories in the current Zendesk catalog can be created on demand.
- Previously linked categories can be renamed or reparented when their label changes in Zendesk; the same ObjectLink is kept for the leaf.
- Catalog selection fails closed on ambiguous fields, pagination loops, missing/invalid options and HTTP errors. A failed category upsert aborts the bootstrap and exposes the failing technical tag in the job error; the next execution safely replays the catalog via ObjectLink. Retries and resume for large ticket jobs remain unchanged.

## Compatibility and limitations

- The same template ID is used for upgrades, so **do not recreate existing category jobs**; the executor bypasses their old ticket-based resource path.
- The selected Zendesk ticket field must be a tagger named Sujet/Subject/Topic or be the sole tagger with options beginning with `topic__`.
- The official `/api/v2/ticket_fields/{id}/options` read endpoint must be available to the Zendesk service account.
- GLPI supports a hierarchy of ITIL categories; the `>` separator is a display convention, never stored as part of each category name.
- A full ticket initial import is explicitly separate from the ticket delta job. Schedule recurring delta to process new/updated tickets, including updates to already imported tickets that become closed. Never reset the delta cursor casually.
- Preflight does not replace functional Zendesk/GLPI integration tests or the full PHPUnit and PHPStan gates.

## Acceptance tests

1. Run the existing category bootstrap job and confirm only field/options GET requests, with no ticket scan.
2. Ensure `Sujet :: Sous-sujet` creates/reconciles a GLPI tree; repeat with no duplicate categories.
3. Import a non-closed ticket with a `topic__...` tag; verify `itilcategories_id` references the expected GLPI leaf.
4. Change the ticket subject/category in Zendesk, run the delta, and verify the existing GLPI ticket is updated without a duplicate.
5. Import a new ticket through the delta; verify its category, requester, comments, attachments.
6. Rename/reparent a Zendesk Sujet option, rerun bootstrap, and verify ObjectLink identity plus updated GLPI hierarchy.
7. Simulate a missing or ambiguous Sujet field, corrupt pagination and HTTP 429, and verify there is no silent-success empty bootstrap.
