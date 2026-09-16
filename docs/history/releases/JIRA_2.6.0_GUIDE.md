# Paramétrage Jira Cloud → GLPI avec UXConnector 2.6.0

## Prérequis

La configuration d'authentification Jira doit être `Active` et utiliser comme endpoint de base :

`https://api.atlassian.com/ex/jira/<CLOUD_ID>`

Le test path recommandé est `/rest/api/3/project` ou `/rest/api/3/myself` selon les scopes du compte de service.

## Job 1 — Projets Jira → Projets GLPI

Dans **UXConnector > Jobs de synchronisation > Nouveau**, cliquer **Preset: Jira projects → GLPI projects**.

Valeurs attendues :

- Direction : `Système externe → GLPI`
- Resource path : `/rest/api/3/project/search?maxResults=50&expand=description,lead`
- Target : `Projet`
- Match field : `code`
- External ID field : `id`
- Mapping : `key→code`, `name→name`, `description→content`

Pour une instance multi-entités, définir une stratégie d'entité fixe ou mappée avant activation.

Créer le job, l'activer, puis utiliser **Run now**. Le premier passage crée/adopte les projets GLPI, les passages suivants utilisent `ObjectLink` pour mettre à jour le même objet.

## Job 2 — Issues Jira → Tickets GLPI

Renseigner la clé Jira (ex. `KAN`), puis cliquer **Preset: Jira issues → GLPI tickets**.

Le preset génère une recherche JQL sur ce projet et les mappings suivants :

| Jira | GLPI | Transformation |
|---|---|---|
| `key` | `externalid` | — |
| `fields.summary` | `name` | — |
| `fields.description` | `content` | `jira_adf_to_text` |
| `fields.created` | `date` | `datetime_glpi` |
| `fields.issuetype.name` | `type` | `map:Bug=1|Incident=1|Task=2|Story=2|Service Request=2|*=2` |
| `fields.priority.name` | `priority` | `map:Highest=6|High=5|Medium=3|Low=2|Lowest=1` |
| `fields.status.statusCategory.key` | `status` | `map:new=1|indeterminate=2|done=5|*=1` |

Les trois `map:` sont des valeurs initiales à valider avec le workflow Jira et la matrice de priorité GLPI du client.

## Pagination

Aucun paramètre supplémentaire n'est nécessaire : UXConnector suit automatiquement :

- `nextPageToken` jusqu'à `isLast=true` pour la recherche JQL Jira ;
- `startAt/maxResults/total` pour la recherche de projets.

## Relation projet/ticket

UXConnector conserve séparément :

- Jira Project ID ↔ GLPI Project ID ;
- Jira Issue ID ↔ GLPI Ticket ID.

GLPI ne possède pas de lien direct Ticket ↔ Project : le lien natif passe par `ProjectTask_Ticket`. UXConnector 2.6.0 n'invente donc pas de ProjectTask automatiquement. Cette relation sera à traiter explicitement dans une version ultérieure si elle est souhaitée.
