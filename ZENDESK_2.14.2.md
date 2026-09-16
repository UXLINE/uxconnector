# UXConnector 2.14.2 — Zendesk inline screenshots + bulk job activation

## Functional corrections

### Inline screenshots in Zendesk comments

Zendesk returns inline screenshots in two distinct places when `include_inline_images=true` is used:

- the comment `body`, usually as a Markdown image reference (`![](https://.../attachments/...)`);
- the comment `attachments[]`, where the same file can be downloaded and the `inline` flag identifies an inline attachment.

UXConnector 2.14.1 copied the Markdown body literally into `ITILFollowup.content`. GLPI does not interpret Zendesk Markdown, so the user saw the Zendesk URL instead of the image.

2.14.2 now:

1. downloads the Zendesk attachment as a native GLPI `Document`;
2. links the `Document` to the `ITILFollowup` (or to the Ticket for the initial duplicated comment);
3. reuses the GLPI `Document.tag` and native `Toolbox::convertTagToImage()` mechanism;
4. rewrites the Zendesk Markdown image reference to a local GLPI `document.send.php` image reference;
5. reuses already imported `zendesk_attachment` ObjectLinks during a replay, so no duplicate Document is created;
6. appends an inline image if Zendesk exposes it in `attachments[]` but omits the Markdown marker from `body`.

Regular non-inline attachments remain Documents without being forced into the rich-text body.

### Replay behavior

A migration job can be replayed after clearing its checkpoint. Existing tickets, comments and attachments are resolved through ObjectLink. This allows 2.14.2 to repair an existing 2.14.1 followup that contains a raw Zendesk image URL without creating a second followup or a second document.

## UI improvement

The synchronization jobs page now supports multi-selection:

- checkbox per job;
- Select all checkbox;
- Activate selection;
- Pause selection.

The existing per-job actions remain available.

## Validation status

This build is a functional migration build. PHP syntax is checked before packaging. Full PHPCS, PHPStan and GLPI Integration qualification remains scheduled after the functional Zendesk migration behavior is frozen.
