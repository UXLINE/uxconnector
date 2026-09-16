# UXConnector 2.17.1 — Correctif performance référentiels GLPI High-Level

Ce correctif cible les synchronisations volumineuses d'assets enrichis par les référentiels GLPI.

## Correctifs

- Cache en mémoire des référentiels résolus pendant un run : entités, statuts, fabricants, types, modèles et lieux.
- Pas de deuxième `update()` GLPI si les IDs de référentiels sont déjà corrects.
- Rafraîchissement du budget `max_execution_time` à chaque frontière de page afin qu'un job multi-pages ne soit pas interrompu uniquement parce que sa durée totale dépasse 30 secondes. Une page individuelle reste bornée par la limite PHP configurée.
- Aucun changement de schéma et aucune recréation des jobs existants.

## Contexte

Avec environ 2 000 ordinateurs, la 2.17.0 pouvait terminer l'enrichissement fonctionnel puis atteindre la limite PHP de 30 secondes lors de la finalisation de l'exécution. Les assets pouvaient donc être correctement mis à jour alors que l'interface affichait une erreur de timeout.
