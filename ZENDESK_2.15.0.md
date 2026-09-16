# UXConnector 2.15.0 — migration volumique Zendesk

## Objectif

Cette version durcit la reprise Zendesk pour plusieurs milliers de tickets sans charger l'ensemble du jeu de données en mémoire et sans parcourir inutilement tout l'historique clos lors de la reprise initiale.

## Stratégie Zendesk

### Job 4 — sujets utilisés

Le bootstrap des catégories ITIL utilise désormais Zendesk Search Export :

`/api/v2/search/export.json?filter[type]=ticket&query=status%3Cclosed&page[size]=100`

Seuls les tickets `new`, `open`, `pending`, `hold` et `solved` sont parcourus. Les catégories GLPI sont créées à partir du champ tagger Zendesk `Sujet` réellement utilisé.

### Job 5 — reprise initiale

La reprise initiale utilise le même Search Export `status<closed`. Elle évite donc de parcourir les anciens tickets `closed` uniquement pour les filtrer ensuite. La taille recommandée est 100 tickets par page.

### Job 6 — delta incrémentiel

Une interaction distincte est ajoutée pour la phase de delta :

`/api/v2/incremental/tickets/cursor.json`

Le curseur Zendesk est persistant. Un ticket déjà importé qui devient `closed` est mis à jour dans GLPI ; un ticket `closed` jamais importé reste ignoré.

## Traitement page par page

`ExecuteSyncJobService` ne concatène plus toutes les pages dans un tableau PHP. Chaque page est traitée dès sa réception puis libérée avant la page suivante.

Pour un import de 10 000 tickets avec des pages de 100, la mémoire du moteur dépend donc essentiellement d'une page, pas des 10 000 tickets.

Pour les jobs à curseur persistant, le checkpoint avance après chaque page sans erreur. Si une page contient au moins une erreur, le checkpoint est gelé avant cette page ; les pages suivantes peuvent néanmoins être traitées. Un prochain run rejoue alors de façon idempotente depuis le dernier checkpoint sûr.

## Rate limit

Le moteur HTTP générique reconnaît HTTP 429 et respecte `Retry-After`, avec un maximum de 5 nouvelles tentatives et une attente plafonnée à 120 secondes par réponse.

Cette protection s'applique également aux appels secondaires effectués par les post-processors, notamment la lecture des commentaires.

## Test synthétique 10 000 tickets

Le package contient :

`tools/zendesk-volume-test.php`

Il simule un Zendesk Search Export paginé sans créer le moindre ticket dans Zendesk ni dans GLPI. Il utilise le vrai `ExecuteSyncJobService`, le vrai moteur de pagination, le vrai mapping de champs et un writer mémoire qui ne persiste rien.

Dans le conteneur GLPI :

```bash
cd /var/www/glpi/plugins/uxconnector
php tools/zendesk-volume-test.php 10000 100
```

Résultat attendu :

```text
Records requested : 10000
Expected pages    : 100
HTTP pages read   : 100
Processed         : 10000
Failed            : 0
Status            : success
Writer count      : 10000
```

Ce test valide le comportement volumétrique du moteur sans polluer le Zendesk de test. Il ne remplace pas une recette fonctionnelle sur un échantillon réel de tickets avec commentaires et pièces jointes.

## Séquence projet recommandée

1. Jobs 1 à 4 : référentiels et catégories.
2. Job 5 : reprise initiale `status<closed`.
3. Contrôle des volumes et échantillons.
4. Activer le job 6 avec un `start_time` choisi avant la reprise initiale afin de ne rater aucune modification intervenue pendant celle-ci.
5. Laisser le job 6 rejouer les changements jusqu'au cutover.
6. Dernier delta, contrôles, puis dump de l'instance de consolidation vers GLPI Cloud.

## Attention lors de la mise à jour depuis 2.14.6

Les SyncJobs déjà créés sont des configurations persistées. Le catalogue 2.15.0 n'écrase pas arbitrairement leur `resource_path`. Pour bénéficier de Search Export sur les jobs 4 et 5, supprimer/recréer ces interactions depuis la connexion Zendesk (ou recréer la connexion de test). Le nouveau job 6 doit également être créé depuis le catalogue 2.15.0.
