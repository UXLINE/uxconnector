# EasyVista Service Manager et UXConnector 2.18.37 candidate

Sources fournies. *EV Service Manager REST API*, sections « REST API pagination », « Access the EV Service Manager REST API » et « Tickets ». *EasyVista OpenAPI Description Service Manager Rest API* 1.9.4, pour Service Manager 2026.2. Cette recette cible les versions qui renvoient les liens de pagination dans le JSON.

| Modèle | Opération | Résultat GLPI |
| --- | --- | --- |
| Importer les incidents | GET `/requests` | Ticket Incident, rapproché par numéro RFC. |
| Importer les demandes de service | GET `/requests` | Ticket Demande, rapproché par numéro RFC. |
| Importer des lieux | GET `/locations` | Lieu à plat, rapproché par libellé français. |
| Importer des ordinateurs | GET `/assets` | Ordinateur rapproché par numéro de série. Le libellé et le numéro d'inventaire sont repris. |
| Importer des entrées de catalogue | GET `/catalog-requests` | Catégorie ITIL à plat, rapprochée par libellé anglais. |
| Créer un ticket | POST `/requests` | Modèle sortant désactivé. Le corps de la requête est fourni pour préparer la recette. |

Pour les tickets entrants, le titre et l'identifiant GLPI sont alimentés par le numéro RFC. Le modèle reprend aussi la date de soumission et fixe le type GLPI. Il utilise un contenu générique. La liste documentée ne garantit pas la présence d'un titre et le détail expose la description comme une ressource liée. Les acteurs, commentaires, pièces jointes, statuts et SLA restent hors du pilote.

## Configurer la connexion et tester

1. Dans le catalogue, choisir **EasyVista Service Manager**. Saisir l'URL HTTPS avec le chemin de compte, par exemple `https://votre-hote/api/v1/40000`. Utiliser un compte technique de lecture et l'authentification Basic ou un jeton Bearer déjà émis par EasyVista. Saisir le jeton sans préfixe. Tester `GET /version`, puis le droit de lecture de la ressource du job.
2. Créer le job en pause, dans une entité GLPI de recette. Saisir la valeur brute du paramètre `search`, sans préfixe `search=` ni encodage URL. Pour un premier test ticket, utiliser par exemple `RFC_NUMBER:"I161026_000005"` en remplaçant le numéro par celui d'un incident de recette. Tester le GET et vérifier le type de tous les objets renvoyés. Les filtres Incident et Demande doivent être disjoints. Garder les deux jobs sur la même connexion pour partager le rapprochement par RFC.
3. Pour les ordinateurs, contrôler que le filtre exclut les autres familles d'actifs et que chaque numéro de série est présent et unique. Pour les lieux et catégories, vérifier l'unicité des libellés et les éventuelles collisions avec les objets existants dans l'entité. Pour les tickets, vérifier que les RFC ne sont pas déjà utilisés par une autre intégration.
4. Activer le job pour une exécution manuelle. Contrôler les champs écrits, l'entité, les effets des règles GLPI, puis rejouer le même lot et vérifier l'absence de doublons. Tester aussi la modification d'un champ mappé et le traitement d'un identifiant absent.
5. Sur un lot de plus de 25 lignes, vérifier que la réponse contient le tableau `records` et un lien `@next` dans le JSON jusqu'à la dernière page. Contrôler que le lien conserve le filtre et reste sur la même origine et sous le chemin du compte. Si le serveur envoie la pagination uniquement dans les en-têtes, adapter sa configuration avant tout import complet.
6. Pour les tickets, confirmer la forme exacte de la réponse du tenant. Le schéma OpenAPI de `GET /requests` décrit une enveloppe `records` imbriquée, alors que le modèle suit la structure de liste à une seule enveloppe présentée dans le guide. Vérifier que les entrées de `records` exposent directement `RFC_NUMBER`. Si le tenant renvoie la structure imbriquée, adapter le chemin de collection avant activation.
7. Remettre le pilote en pause après la recette.

## Conditions de mise en service du flux sortant

Le Swagger documente une réponse HTTP 201 contenant `HREF`. Confirmer sa forme sur le tenant et implémenter l'extraction validée du numéro RFC avant de rapprocher le flux avec les jobs entrants. Définir aussi le code catalogue, le bénéficiaire, les champs obligatoires, les conditions de déclenchement et la prévention des doublons si une création réussit sans réponse reçue. Le modèle laisse le démarrage du workflow désactivé.

## Contrôles et publication

Les routes, les champs déclarés, le corps de création, le format du catalogue et l'intégrité de l'archive sont contrôlés statiquement. Les tests PHP et la recette avec une instance GLPI et EasyVista restent à exécuter. La variante API InPark demeure exclue. L'archive reprend les catalogues de la 2.18.36 et ne modifie ni le moteur PHP ni le schéma SQL. Avant soumission à Teclib, vérifier l’URL de téléchargement indiquée dans `uxconnector.xml`.
