# UXConnector 2.14.4 — Zendesk inline screenshots and connection bulk jobs

This functional patch addresses two issues observed during real Zendesk migration testing on GLPI 11.0.8.

## Inline screenshots

Zendesk normally returns inline images in `comment.attachments` when `include_inline_images=true`. Some comments can still expose the pasted screenshot only through a tokenized URL in `body` / `html_body`. UXConnector 2.14.4 detects these `/attachments/...` URLs, downloads the image, passes it through GLPI's native document pipeline, links the resulting document to the ticket followup, and replaces the external Zendesk reference with the GLPI document tag.

The downloader tries tokenized attachment URLs without propagating OAuth credentials and still enforces the configured destination policy. If the same-host URL requires authentication, the Zendesk OAuth path is used as a fallback.

## Bulk interaction actions

The previous bulk toolbar existed on the global synchronization-job page, while standard Zendesk interactions are commonly managed from the connection detail page. Version 2.14.4 adds visible checkboxes and **Activate selected interactions / Pause selected interactions** directly to the connection's **Interactions** section.

## Replay

The import remains idempotent through ObjectLink. Reset only the Zendesk non-closed ticket job checkpoint to replay comments/attachments without recreating the already linked ticket.
