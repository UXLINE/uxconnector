# UXConnector 2.14.2 — Zendesk comments and screenshots

Functional patch over 2.14.0.

## Fixed

- Zendesk ticket comments are read from `/api/v2/tickets/{id}/comments.json`.
- `include_inline_images=true` is requested so pasted screenshots are returned in `attachments[]`.
- The initial Zendesk comment is still not duplicated as a GLPI followup when it is identical to `Ticket.description`.
- Attachments/screenshots on that initial comment are nevertheless imported and linked to the GLPI Ticket.
- Attachments on subsequent comments are imported as GLPI Documents and linked to the corresponding `ITILFollowup`.
- Each Zendesk attachment is tracked with an ObjectLink (`zendesk_attachment`) for replay/idempotence.
- Deleted attachments are ignored and attachments flagged `malware_found` are rejected.
- External `content_url` values are downloaded without forwarding Zendesk credentials; destinations remain subject to UXConnector's SSRF policy.

## Expected behavior on the two POC tickets

If a Zendesk ticket has only its creation comment and that text is identical to the ticket description, GLPI will show the text once in the ticket description, not twice in the timeline. Any screenshot/file attached to that creation comment is still imported.

A later Zendesk reply becomes an `ITILFollowup`; its screenshots/files become GLPI Documents linked to that followup.

## Replay after upgrading from 2.14.0

Tickets already passed by the incremental cursor will not necessarily be revisited immediately. For POC validation, reset only the checkpoint of `zendesk.tickets.nonclosed.inbound`, then run that job again. ObjectLinks prevent duplicate Tickets, Users, Groups and already-imported relations.
