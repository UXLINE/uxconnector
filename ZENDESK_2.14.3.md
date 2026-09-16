# UXConnector 2.14.3 — Zendesk inline screenshots and bulk jobs

This functional hotfix replaces the 2.14.2 inline-image implementation with GLPI 11 native rich-text attachment handling (`CommonDBTM::addFiles()`). Zendesk inline attachment URLs are replaced by GLPI document tags, then GLPI creates `Document` / `Document_Item` links and renders the local image. Existing `zendesk_attachment` ObjectLinks are reused.

The synchronization jobs page now exposes an explicit bulk-action banner with a Select all control, selected count, Activate selected jobs and Pause selected jobs.

After deploying this version, clear GLPI/Twig cache before validating the UI.
