# UXConnector 2.16.0 — consolidation du parc GLPI 11 High-Level API

## Objectif

Cette version ajoute au connecteur `GLPI 11 High-Level API` un premier lot de jobs de lecture de parc destiné à consolider plusieurs instances GLPI 11 dans l'instance GLPI qui héberge UXConnector.

Connexion source attendue :

- Endpoint : `https://<glpi-source>/api.php`
- OAuth2 : Password Grant
- Token URL : `https://<glpi-source>/api.php/token`
- Scope : `api`
- Compte technique GLPI disposant au minimum des droits de lecture sur les types d'actifs à reprendre.

## Jobs disponibles

1. `GLPI source → Ordinateurs` (`/Assets/Computer`)
2. `GLPI source → Moniteurs` (`/Assets/Monitor`)
3. `GLPI source → Téléphones` (`/Assets/Phone`)
4. `GLPI source → Périphériques` (`/Assets/Peripheral`)
5. `GLPI source → Imprimantes` (`/Assets/Printer`)
6. `GLPI source → Équipements réseau` (`/Assets/NetworkEquipment`)

Chaque job lit les listes par pages de 100 éléments par défaut via `start` + `limit` et traite chaque page immédiatement.

## Pagination High-Level API

Le runtime dispose maintenant du type de pagination `count_offset` pour les endpoints qui retournent directement un tableau JSON, sans métadonnées `total`/`offset` dans le corps.

Exemple :

```text
/Assets/Computer?start=0&limit=100
/Assets/Computer?start=100&limit=100
/Assets/Computer?start=200&limit=100
...
```

La lecture s'arrête sur une page vide ou plus courte que la taille demandée.

## Traçabilité et rejouabilité

La clé primaire `id` du GLPI source reste l'identifiant externe du job. UXConnector l'enregistre dans `ObjectLink`, avec la connexion source et le type d'objet :

```text
connexion GLPI PARC 1 + glpi_hl_computer + id 2481
    → Computer cible 731
```

Une seconde exécution met donc à jour l'objet déjà lié au lieu d'en créer un nouveau.

## Réconciliation multi-GLPI

Le match spécial `asset_identity` est ajouté pour les actifs :

- Ordinateur / équipement réseau : UUID → numéro de série → numéro d'inventaire → nom.
- Moniteur / téléphone / périphérique / imprimante : UUID → numéro de série → numéro d'inventaire.

Le nom seul n'est volontairement pas utilisé pour fusionner les moniteurs, téléphones, périphériques et imprimantes, car plusieurs objets différents portent fréquemment le même libellé.

Quand aucun identifiant matériel fiable n'est présent, l'objet peut tout de même être créé et son `ObjectLink` garantit ensuite les relectures sur la même source.

Les templates (`is_template`) et éléments supprimés (`is_deleted`) du GLPI source sont ignorés.

## Champs copiés dans ce premier lot

Cette première phase copie les champs scalaires sûrs et directement compatibles : nom, numéros de série/inventaire, UUID, commentaire, contact et plusieurs attributs propres à chaque type (taille/HDMI pour les écrans, ligne/casque pour les téléphones, compteurs pour les imprimantes, RAM/CPU pour les équipements réseau, etc.).

Les identifiants numériques de listes déroulantes du GLPI source (`manufacturer.id`, `model.id`, `type.id`, `status.id`, `location.id`, etc.) ne sont **pas** copiés tels quels : ces IDs sont locaux à chaque base GLPI et pourraient désigner un autre objet dans l'instance de consolidation.

Une phase suivante devra donc créer/réconcilier ces référentiels par libellé avant de les rattacher aux actifs. Même principe pour les relations ordinateur ↔ moniteur/téléphone/périphérique/imprimante.

## Recommandation premier atelier INDIGO

Pour le premier GLPI source :

1. créer/tester la connexion `GLPI 11 High-Level API` ;
2. créer uniquement le job `1 - GLPI source → Ordinateurs` avec `page_size = 100` ;
3. exécuter le job ;
4. comparer volumes source/cible et un échantillon d'ordinateurs ;
5. activer ensuite les jobs 2 à 6 selon le périmètre ;
6. relever les référentiels et relations à reprendre dans la deuxième passe.

L'objectif de ce premier atelier est d'abord de valider la lecture complète, la pagination, la création, le rejeu et la déduplication avant d'enrichir les actifs avec les référentiels associés.
