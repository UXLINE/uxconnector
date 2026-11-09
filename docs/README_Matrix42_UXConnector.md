# Matrix42 Enterprise Service Management ↔ GLPI via UXConnector

Modèle de catalogue Matrix42 1.0.0 intégré à l’archive générique `uxconnector-2.18.35-candidate (2).zip`. Ce catalogue est inclus dans la version de recette 2.18.36.

## Jobs

| Modèle | État | Données et sens |
| --- | --- | --- |
| `matrix42.incident.inbound.pilot` | Instanciable en recette | Matrix42 → GLPI. GUID du fragment, titre, description si présente. Type GLPI Incident (1). |
| `matrix42.request.inbound.pilot` | Instanciable en recette | Matrix42 → GLPI. Mêmes champs. Type GLPI Demande (2). |
| `matrix42.incident.outbound` | Bloqué dans le catalogue | GLPI → Matrix42. Création d’un incident via le service d’objets, modèle de corps JSON fourni. |
| `matrix42.request.outbound` | Bloqué dans le catalogue | GLPI → Matrix42. Création d’une demande de service via le CI à confirmer sur l’instance. |

Les jobs entrants réconcilient `ID` sur `Ticket.externalid`, puis s’appuient sur le lien UXConnector pour les rejouements. Utiliser le même type d’objet externe et la même connexion pour les deux jobs entrants. Le filtre Matrix42 détermine le type importé. Un GUID présent dans les deux filtres pourrait entraîner la requalification du même ticket. Une mise à jour réapplique les champs mappés, dont le titre et la description. Les statuts, catégories, demandeurs, groupes, suivis, pièces jointes et SLA restent hors du périmètre de ce pilote.

## Installation en recette

1. Déployer le répertoire `uxconnector/` de cette archive en recette selon la procédure de déploiement du plugin. Le catalogue Matrix42 est fourni sous `uxconnector/resources/connectors/matrix42/connector.json`. L’ajout du modèle ne requiert aucune migration de base de données.
2. Ouvrir le catalogue UXConnector et créer une connexion de type **Matrix42 Enterprise Service Management**. Saisir l’URL réelle avec le préfixe applicatif, par exemple `https://matrix42.example.invalid/m42Services`. Les chemins des jobs sont relatifs à ce préfixe. Conserver la vérification TLS et importer la CA de l’organisation si nécessaire.
3. Dans Matrix42, donner au compte technique l’accès à l’opération de liste des fragments et les droits de lecture des objets du pilote. Vérifier l’audience du service Web et les droits sur les données.
4. Échanger l’API Token contre un Access Token au moyen de `POST /m42Services/api/ApiToken/GenerateAccessTokenFromApiToken/`, en présentant l’API Token sous la forme `Authorization: Bearer <API Token>`. Dans UXConnector, configurer **Jeton Bearer statique** avec la valeur du **jeton d’accès obtenu**, sans le préfixe `Bearer`. Utiliser le chemin de test du catalogue ou un GET borné à un objet de recette. Contrôler sa durée de validité. Ce montage convient à une exécution manuelle. La planification demande un renouvellement automatique compatible avec le flux Matrix42.
5. Dans le catalogue de la connexion, sélectionner le modèle entrant, fixer une entité GLPI de recette et laisser l’activation immédiate décochée. Configurer un périmètre A-SQL distinct par type et vérifier le GET exact avant activation.

## Préparer et exécuter le pilote entrant

- Pour chaque flux, récupérer dans Matrix42 un GUID de fragment `SPSActivityClassBase` et vérifier son type métier. Exemple de filtre mono-enregistrement à tester dans Matrix42 avec le GUID réel : `ID = '11111111-1111-4111-8111-111111111111'`. Saisir le filtre sans encodage URL dans le modèle.
- Tester `GET /api/data/fragments/SPSActivityClassBase` avec `columns=Subject,DescriptionHTML`, `sort=ID ASC`, le filtre et `pagesize=10`. Confirmer le numéro de première page accepté par le serveur puis le saisir dans le modèle. Attendre un tableau JSON racine avec `ID`, `Subject` et, le cas échéant, `DescriptionHTML`. Vérifier qu’un résultat appartient à un seul filtre.
- Ce modèle ne parcourt qu’une page. Garder chaque lot pilote sous 10 lignes. Pour une reprise de masse, prévoir une pagination native par numéro de page avec un arrêt fiable, un ordre stable et une stratégie de reprise. Ne pas configurer la pagination UXConnector par offset à partir du numéro de page.
- En recette, confirmer que `glpi_tickets.externalid` est disponible et accepte un GUID complet de 36 caractères. Vérifier qu’il ne contient pas déjà le même GUID pour une autre intégration dans l’entité cible.
- Activer et lancer manuellement le job entrant dans l’entité de recette, puis le remettre en pause. Contrôler les créations et les notifications GLPI. Si le flux métier doit transférer les descriptions HTML, vérifier le rendu et le traitement des liens ou images distantes sur un ticket de test.

| Test | Résultat attendu en recette |
| --- | --- |
| Jeton d’accès et liste bornée | HTTP 200, tableau JSON et seuls les objets autorisés. |
| Incident puis demande | Un ticket de chaque type dans l’entité sélectionnée, avec son GUID de fragment. |
| Deuxième lancement du même lot | Mêmes IDs GLPI, aucune création supplémentaire. |
| Changement du titre à la source | Même ticket GLPI, titre actualisé. |
| Deux filtres distincts et objet hors filtre | Aucun ticket pour l’objet hors filtre, aucune requalification entre jobs. |
| Jeton expiré ou droits insuffisants | Erreur d’accès visible. Aucun ticket importé par cette requête. |

## Conditions d’activation des flux sortants

- Le service générique `POST /api/data/objects/{ciName}` documente un GUID objet en réponse. Capturer la réponse réelle, y compris son type JSON. UXConnector 2.18.35 attend un tableau ou un objet JSON pour lire le chemin de l’identifiant. Un GUID JSON scalaire nécessite une adaptation. La route `POST /api/ticket/create` documente une réponse 204 sans corps et exige une autre stratégie de liaison.
- Confirmer les champs obligatoires, leurs types, les GUID de catégorie et d’initiateur ainsi que les règles métier déclenchées. Tester le CI de demande de service et les champs supplémentaires avant toute activation.
- Pour une mise à jour générique, Matrix42 documente `PUT /api/data/objects/{ciName}` avec le GUID objet dans le champ racine `ID`, l’identité du fragment dans son sous-objet et, pour gérer la concurrence, son `TimeStamp`. Le moteur sortant 2.18.35 ne sait pas injecter le GUID externe dans le corps à partir du lien, ni réaliser le GET préalable du fragment. La définition de mise à jour reste donc à implémenter.
- Définir une clé de corrélation GLPI stable côté Matrix42 et un mécanisme de recherche ou de création idempotente. Tester une perte de réponse après création et deux événements concurrents. Le contrôle par lien local seul ne couvre pas ces cas.
- Le déclencheur standard sortant cible une entité et un type d’objet GLPI. Avant d’activer les deux modèles sortants, ajouter un filtre effectif sur le type du ticket ou attribuer des périmètres disjoints. Pour un aller-retour sur les mêmes tickets, relier explicitement le GUID objet au GUID fragment, répartir la propriété des champs et prévoir une prévention de boucle.

## Références

- PDF fourni *Services Web – Intégration d’API REST*, p. 4–6.
- PDF fourni *API – Service de données génériques*, p. 2–3.
- [Jetons des services Web Matrix42](https://docs.matrix42.com/1074558_web-services/3463381_web-services-tokens-generate-api-token)
- [Liste de fragments](https://docs.matrix42.com/1067929_public-api-reference-documentation/3463314_fragments-data-service-get-a-list-of-fragments)
- [Création d’objet](https://docs.matrix42.com/en_US/3463353_object-data-service-create-object)
- [Mise à jour d’objet](https://docs.matrix42.com/1067929_public-api-reference-documentation/3463357_object-data-service-update-object)
- [Création de ticket, incident ou demande](https://docs.matrix42.com/3463287_create-an-incident-or-a-service-request?kb_language=en_US)

Contrôles effectués hors instance sur le manifeste et le format du catalogue. La recette de bout en bout requiert une instance Matrix42 et un GLPI de test.
