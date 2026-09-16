# UXConnector 2.14.5 — Zendesk signed attachment URLs

## Fix: inline screenshots were detected but never downloaded

Zendesk returns pasted screenshots as attachments whose `content_url` contains a query string, for example `...?name=image.png`, and redirects to a signed `zdusercontent.com` URL containing `?token=...`.

Version 2.14.4 attempted to validate the complete download URL with the generic `Endpoint` value object. `Endpoint` is intentionally designed for API base URLs and rejects query strings, so the attachment download was rejected before any file could be written. The authenticated fallback then sent the OAuth header to the attachment URL, which Zendesk can answer with HTTP 406.

2.14.5 validates the full URL as HTTPS, applies the existing SSRF destination policy to the URL origin/host, and preserves the complete signed query string for the actual unauthenticated download and redirects.

## Bulk interaction actions

The bulk toolbar on the connection detail page is now rendered whenever the profile can update synchronization jobs, instead of additionally depending on the top-level job-list count. This makes the bulk controls visible alongside connector-template interactions.
