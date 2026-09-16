# UXConnector 2.8.3 — Navigation Connexions / Interactions

Correctif UX sans changement de schéma SQL.

## Changements

- La liste `Connections` expose désormais : **Manage**, **Edit**, **Interactions**, **Test**, **Activate/Disable**, **Delete** selon les droits et le statut.
- **Interactions** ouvre directement `connection.php?id=<id>#interactions`.
- La fiche connexion possède une ancre stable `#interactions` et un raccourci dans le bandeau d'actions.
- `ConnectionController::index()` fournit `can_read_jobs` à Twig via le droit GLPI `plugin_uxconnector_syncjob` / `READ`.

Aucune logique métier Jira n'est ajoutée au moteur générique.
