# UXConnector 2.18.36 candidate

Archive de recette pour GLPI 11. Base générique 2.18.35, GPL-3.0-or-later, PHP 8.2 minimum selon les métadonnées du plugin. Les changements de catalogue ne nécessitent aucune nouvelle migration SQL.

| Périmètre | Contenu |
| --- | --- |
| Catalogue | 13 connecteurs et 34 définitions de jobs, dont 26 instanciables. Les 8 autres sont désactivées ou retirées du catalogue courant. |
| Jira Cloud | 5 modèles natifs conservés pour les projets, issues et événements webhook. |
| Jira Service Management Cloud | 2 nouveaux imports pilotes, Incident et Demande. |
| Matrix42 | 2 imports pilotes. Les 2 modèles sortants restent désactivés. |
| Spécifications Jira et JSM | Les 32 scénarios de conception étaient joints à la candidate 2.18.36. JIRA-P01 et JIRA-P02 ont une couverture native existante pour l'import et la création simple de projets. JIRA-S01 et JIRA-S02 ont une couverture partielle avec les pilotes JSM. Les scénarios nécessitant d'autres traitements restent des spécifications. |

La variante de connexion à l'API InPark est exclue. Les trois modèles GLPI REST v1 de migration Télécom, Réseau et Microsoft de la base générique sont conservés avec leurs mappings Fields historiques.

## Recette

1. Mettre à jour le plugin dans un GLPI 11 de recette. Vérifier que GLPI affiche la version 2.18.36 et que les connexions et jobs existants s'ouvrent.
2. Vérifier que le catalogue présente les connecteurs Matrix42 et Jira Service Management Cloud. Vérifier les deux modèles entrants Matrix42 et l'indisponibilité des deux sortants. Suivre [la recette Matrix42](../README_Matrix42_UXConnector.md).
3. Pour JSM, créer une connexion dédiée à l'origine du site, par exemple `https://votre-site.atlassian.net`, avec un compte technique et un mode d'authentification compatible avec cette URL. Vérifier que le compte voit les demandes attendues.
4. Instancier les deux pilotes JSM en pause, dans une entité GLPI de recette. Saisir le numéro du portail et un numéro de type de demande distinct pour chaque pilote. Contrôler le GET sur un petit lot avant activation et vérifier que les valeurs de `serviceDeskId` et `requestTypeId` correspondent au périmètre voulu.
5. Exécuter manuellement. Vérifier le titre, la date, la clé Jira, la classification Incident ou Demande et l'entité cible. Le contenu est une mention d'import fixe. Les descriptions, acteurs, statuts, commentaires, pièces jointes et SLA sont hors du pilote.
6. Rejouer le lot et vérifier l'absence de doublons. Vérifier le suivi de `_links.next` sur deux pages en contrôlant les filtres de la deuxième requête. Utiliser des jeux de données disjoints entre le modèle Jira Cloud généraliste et les pilotes JSM. Le rapprochement initial utilise `Ticket.externalid` et une clé Jira peut être identique sur deux sites. Utiliser des entités de recette distinctes pour les sites présentant les mêmes clés. La pagination JSM suit l'ordre de dernière activité et le pilote ne constitue pas une migration de masse sur un jeu de données en évolution.
7. Le préflight de cette version se trouve dans son archive candidate d’origine.

Référence du flux JSM [Get customer requests](https://developer.atlassian.com/cloud/jira/service-desk/rest/api-group-request/#api-rest-servicedeskapi-request-get).

## Publication GitHub et validation Teclib

Pour la distribution, utiliser le descripteur de la version publiée. Vérifier le ZIP publié avec la procédure de recette ci-dessus. Relire les mappings historiques et les notes liés à INDIGO avant de rendre le code source public.

Contrôles réalisés pour cette archive : structure ZIP, cohérence des métadonnées, parsing des manifestes et des spécifications, unicité des identifiants et comparaison des sources avec la base générique et le lot Matrix42. La recette PHP et les exécutions réelles GLPI, Jira et Matrix42 restent à faire.
