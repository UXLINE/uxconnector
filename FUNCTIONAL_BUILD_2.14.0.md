# UXConnector 2.14.0 — build fonctionnel avant qualification

Cette archive correspond à l'itération fonctionnelle demandée avant reprise des contrôles qualité/sécurité.

## Réalisé

- Zendesk OAuth2 Client Credentials activé dans le catalogue.
- Client credentials envoyés dans le corps du POST OAuth2 (compatible Zendesk et Microsoft identity platform).
- Cache mémoire du bearer token jusqu'à peu avant expiration.
- Bootstrap Zendesk Users -> GLPI Users.
- Bootstrap Zendesk Groups -> GLPI Groups.
- Bootstrap Zendesk Organizations -> GLPI Entities.
- Bootstrap Zendesk Ticket Forms -> GLPI ITIL Categories.
- Nouveau job cible `zendesk.tickets.nonclosed.inbound`.
- Première importation limitée à `new`, `open`, `pending`, `hold`, `solved` ; `closed` est ignoré.
- Un ticket déjà importé qui devient `closed` est mis à jour pendant les deltas.
- Reconstruction demandeur / assigné / groupe / entité / catégorie sur le ticket.
- Import des commentaires Zendesk en suivis GLPI avec public/privé, auteur, date et idempotence.
- Déduplication du commentaire de création lorsqu'il est identique à la description.
- Extension de la liste blanche des itemtypes génériques : Group, Entity, ITILCategory et ITILFollowup.

## Contrôles exécutés dans le runtime de génération

- `php -l` sur src/tests/front/inc/sql + setup.php + hook.php : OK.
- Chargement du catalogue JSON et présence des 5 jobs Zendesk cibles : OK.

## Volontairement différé

Le demandeur a choisi de terminer le périmètre fonctionnel avant la qualification technique. Ne pas considérer ce build comme qualifié Marketplace tant que les contrôles suivants n'ont pas été rejoués sur le Docker GLPI :

- PHPCS GlpiStandard ;
- PHPStan avec bootstrap GLPI ;
- PHPUnit Unit ;
- PHPUnit Integration ;
- revue de sécurité ;
- recette fonctionnelle sur la vraie API Zendesk.

Restent également à réaliser fonctionnellement avant cutover complet :

- pièces jointes Zendesk -> Document/Document_Item ;
- hiérarchie ITIL avancée à partir des custom fields métier ;
- appartenance des utilisateurs aux groupes ;
- mapping de profils selon rôle Zendesk ;
- jobs de consolidation des 4 GLPI sources après probe réel de leur API et validation des règles de dédoublonnage/référentiels.
