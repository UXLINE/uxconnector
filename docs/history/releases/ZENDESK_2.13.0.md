# UXConnector 2.13.0 — Zendesk Support

Cette version ajoute un provider **Zendesk Support** au catalogue UXConnector et deux templates de tickets :

- `zendesk.tickets.inbound` : reprend tous les tickets disponibles dans le flux incrémentiel ;
- `zendesk.tickets.active.inbound` : au premier passage ne crée dans GLPI que les tickets `new`, `open`, `pending` et `hold`. Un ticket déjà lié par `ObjectLink` continue ensuite d'être traité quand il devient `solved` ou `closed`.

## Authentification

### API token Zendesk

Créer une configuration d'authentification **Basic** :

- endpoint : `https://<sous-domaine>.zendesk.com`
- secret Basic (champ secret UXConnector) : `<email-agent>/token:<api_token>`
- test path : `/api/v2/users/me.json`

Le secret reste stocké par le Credential Vault UXConnector.

### OAuth

Une configuration `bearer_token` peut également être utilisée avec un access token OAuth Zendesk.

## Export incrémentiel

Le template utilise :

`GET /api/v2/incremental/tickets/cursor.json?start_time=...`

Puis UXConnector conserve `after_cursor` dans `glpi_plugin_uxconnector_sync_checkpoints`. Les pages suivantes et les exécutions suivantes utilisent `cursor=...` sans réenvoyer `start_time`.

Le checkpoint n'est avancé que si la page complète a été importée sans échec de record. Cela évite de perdre un ticket lors d'une erreur de mapping/écriture.

## Mapping fourni

| Zendesk | GLPI |
|---|---|
| `id` | `Ticket.externalid` |
| `subject` | `Ticket.name` |
| `description` | `Ticket.content` |
| `status` | `Ticket.status` |
| `priority` | `Ticket.priority` |
| `type` | `Ticket.type` |
| `created_at` | `Ticket.date` |

Statuts : `new→1`, `open→2`, `pending/hold→4`, `solved→5`, `closed→6`.

## Limite volontaire de ce build

Le moteur de template 2.13.0 importe le **ticket natif** et son cycle de vie. Les commentaires, notes internes, pièces jointes et la résolution avancée requester/assignee/group nécessitent un importeur Zendesk spécialisé car ces données vivent sur des endpoints secondaires par ticket. Ils ne sont pas simulés dans ce ZIP.
