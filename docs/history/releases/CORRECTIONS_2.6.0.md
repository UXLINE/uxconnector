# UXConnector 2.6.0 — Jira Projects + Issues en lecture seule

Cette version étend le moteur générique INBOUND pour permettre un premier usage ITSM Jira Cloud sans coder un connecteur Jira monolithique.

## Capacités ajoutées

- `Project` et `Ticket` dans `TargetItemtype`.
- JSON paths (`fields.summary`, `fields.status.statusCategory.key`).
- `@literal:<valeur>` pour injecter des constantes typées dans les mappings génériques.
- wrappers `values` et `issues`.
- pagination Jira Cloud : `startAt/maxResults/total` et `nextPageToken/isLast`, y compris les réponses projet qui exposent `isLast` sans cursor.
- conversion ADF Jira vers texte GLPI.
- mapping de valeurs configurable via `map:`.
- presets UI Jira Projects et Jira Issues.

## Preset Jira Projects → GLPI Projects

- Resource path : `/rest/api/3/project/search?maxResults=50&expand=description,lead`
- Target : `project`
- External ID : `id`
- Match GLPI : `code`
- Mappings : `key→code`, `name→name`, `description→content`.

## Preset Jira Issues → GLPI Tickets

- Resource path : `/rest/api/3/search/jql?jql=project%20%3D%20<KEY>&maxResults=50&fields=summary,description,status,priority,issuetype,created,updated,project`
- Target : `ticket`
- External ID : `id`
- Match GLPI : `externalid`
- Mappings proposés :
  - `key → externalid`
  - `fields.summary → name`
  - `fields.description → content` (`jira_adf_to_text`)
  - `fields.created → date` (`datetime_glpi`)
  - `fields.issuetype.name → type` avec un `map:` éditable (Bug/Incident → Incident GLPI, Task/Story → Demande par défaut)
  - `fields.priority.name → priority` avec un `map:` éditable
  - `fields.status.statusCategory.key → status` avec un `map:` éditable

## Limite volontaire

La synchronisation est encore INBOUND uniquement. Aucun objet n'est créé/modifié dans Jira par cette version.
