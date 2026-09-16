# UXConnector 2.14.0 — Migration Zendesk vers GLPI vierge

## Périmètre fonctionnel de cette révision

Cette révision transforme le connecteur Zendesk 2.13 en flux de migration ordonné pour une instance GLPI de consolidation.

Ordre recommandé dans le catalogue Zendesk :

1. `zendesk.users.bootstrap`
2. `zendesk.groups.bootstrap`
3. `zendesk.organizations.bootstrap` (si l'organisation Zendesk doit devenir une entité GLPI)
4. `zendesk.ticket-forms.bootstrap`
5. `zendesk.tickets.nonclosed.inbound`

Les ObjectLinks rendent chaque étape rejouable sans recréer les mêmes objets.

## Authentification

Le provider accepte désormais `oauth2_client_credentials`.

Pour Zendesk :

- endpoint : `https://<subdomain>.zendesk.com`
- token URL : `https://<subdomain>.zendesk.com/oauth/tokens`
- client ID : identifiant du client OAuth confidentiel
- client secret : secret du client
- scope : `read`
- test path : `/api/v2/users/me.json`

Le client OAuth2 place `client_id` et `client_secret` dans le corps `application/x-www-form-urlencoded`, compatible avec Zendesk, et réutilise le token en mémoire jusqu'à peu avant son expiration.

## Règle de sélection des tickets

Le job cible du projet est `zendesk.tickets.nonclosed.inbound`.

À la première rencontre :

- `new` : importé
- `open` : importé
- `pending` : importé
- `hold` : importé
- `solved` : importé
- `closed` : ignoré

Un ticket déjà importé et qui devient ensuite `closed` est cependant mis à jour dans GLPI pendant un delta, afin de ne pas laisser un ticket obsolète en cours/résolu au moment du cutover.

## Reconstruction des relations

Après création/mise à jour du Ticket GLPI, le post-processeur Zendesk utilise les ObjectLinks issus du bootstrap pour reconstruire :

- `requester_id` -> `Ticket_User` demandeur
- `assignee_id` -> `Ticket_User` assigné
- `group_id` -> `Group_Ticket` assigné
- `organization_id` -> `Ticket.entities_id`
- `ticket_form_id` -> `Ticket.itilcategories_id`

Si une référence nécessaire n'a pas été bootstrappée, le ticket est compté en échec et le curseur incrémental n'avance pas. Le bootstrap peut alors être corrigé puis le job rejoué sans créer de doublons.

## Suivis

Les commentaires Zendesk sont lus via `/api/v2/tickets/{id}/comments.json` et créés comme `ITILFollowup` :

- auteur résolu via `zendesk_user` ObjectLink ;
- `public=true` -> suivi public ;
- `public=false` -> suivi privé ;
- date Zendesk conservée ;
- premier commentaire ignoré s'il est identique à la description initiale du ticket ;
- chaque commentaire créé reçoit un ObjectLink `zendesk_comment` pour l'idempotence.

## Catégories ITIL

Dans ce premier bootstrap, chaque formulaire Zendesk devient une catégorie ITIL GLPI. Cela donne un référentiel initial cohérent et rejouable, mais ce n'est pas encore la matrice ITIL métier finale.

Pour le projet réel, une itération suivante devra permettre de générer une hiérarchie de catégories depuis un ou plusieurs champs Zendesk choisis (par exemple un champ métier de sujet/service), et d'ignorer les champs générés par l'IA tels que sentiment, langue ou confiance.

## Utilisateurs et future synchronisation Entra ID

Les utilisateurs Zendesk sont créés avec l'adresse e-mail normalisée comme login GLPI. L'ID Zendesk reste uniquement la clé externe ObjectLink.

Cette convention facilite le rapprochement ultérieur avec Entra ID/SCIM, mais la recette finale devra vérifier le choix de la clé de corrélation Entra (UPN/mail) avant activation SCIM afin d'éviter les doublons.

## Ce qui reste volontairement hors de cette révision fonctionnelle

Avant qualification finale :

- import des pièces jointes Zendesk en `Document` / `Document_Item` ;
- mapping métier avancé des custom fields vers catégories/lieux/autres référentiels ;
- création des appartenances utilisateur <-> groupe depuis Zendesk ;
- profil GLPI par rôle Zendesk ;
- templates de consolidation des 4 GLPI sources : ils seront construits après un test réel de leur High-Level API afin de ne pas figer des chemins/paginations ou mappings de dropdowns non vérifiés ;
- rapport de contrôle de volumétrie et écarts par source ;
- PHPCS / PHPStan / tests d'intégration / revue sécurité finale.

