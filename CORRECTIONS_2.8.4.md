# UXConnector 2.8.4 — Interaction creation routing fix

## Corrected defect

A standard interaction form posted two intent markers at once: `instantiate=1` on the submit button and `activate=1` on the "Activate immediately" checkbox. `front/connection.php` checked `activate` before `instantiate`, so clicking "Create interaction" on an active connection called `ActivateAuthConfigService` instead of `InstantiateJobTemplateService`. Because the connection was already `active`, the domain correctly rejected a second activation with: `Cannot activate an AuthConfig from status "active". It must first reach "testing".`

## Fix

- Checkbox renamed to `activate_immediately`.
- `ConnectionController::instantiate()` reads `activate_immediately`.
- `instantiate` is dispatched before connection lifecycle actions as a defense-in-depth measure.
- No schema migration.
