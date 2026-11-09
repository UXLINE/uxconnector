# UXConnector 2.18.21 - Source Fields diagnostics (candidate de recette)

## Objectif

Fiabiliser le contrôle **bloquant avant écriture** de la source Fields GLPI REST v1, notamment dans le banc de test Datawise (GLPI 11) et pour les vrais GLPI 10 INDIGO. Conserver les profils Télécoms, Réseau, Microsoft et tous les flux Zendesk de la 2.18.20.

## Modifications

- Nouvelle page administrative **Inspect source Fields (read only)** sur une connexion REST v1 ; analyse les métadonnées de `/listSearchOptions/Location` avec la même API authentifiée que le job.
- Affiche les identifiants d'options REST réellement exposés, leurs correspondances aux champs Télécoms, les absences et les ambiguïtés. Pas de valeurs d'enregistrements, de secrets ou de listes déroulantes.
- Correction de la recherche des champs par noms techniques et libellés dans un bloc identifié, y compris lorsque l'option source utilise des métadonnées différentes selon GLPI 10/11.
- Contrôle préalable strict **conservé** : aucun contournement d'un champ manquant ou ambigu ; pas de construction de fausses valeurs.
- Tests de non-régression sur le matching, les faux positifs natifs, l'ambiguïté, les champs de listes et les métadonnées divulguées.

## Limites

- Un champ présent dans Fields mais absent de `/listSearchOptions/Location` reste **indisponible** pour le job dans sa forme actuelle. Le diagnostic permettra de le confirmer, puis de décider si un second mécanisme de lecture est nécessaire.
- Le banc Datawise n'est pas strictement équivalent au GLPI 10 INDIGO (types et options de listes différents).
- Ce correctif n'implémente pas la lecture des valeurs de dropdown absentes des réponses REST ni ne corrige les associations matérielles non qualifiées.
- Les tests unitaires complets PHPUnit et la validation sur une API Datawise réelle restent nécessaires avant déploiement client / soumission éditeur.

## Recette suggérée

1. Sauvegarder GLPI 11 local et installer 2.18.21 dans le Docker de test, sans toucher à INDIGO.
2. Exécuter `sh tools/preflight-2.18.21.sh`, puis PHPUnit et PHPStan avec des limites mémoire adaptées.
3. Dans UXConnector > Connexions > connexion Datawise : **Inspect source Fields (read only)**.
4. Vérifier les options `equipment`, `equipment_value`, `subscription`, `billing_account` ; les codes `MISSING` doivent être expliqués par la liste des options renvoyées par l'API.
5. Ne relancer le job #22 qu'une fois toutes les options obligatoires correctement mappées et la structure Fields cible conforme ; laisser #23 en pause pour éviter les traitements concurrents.
6. Vérifier le nom de l'appelant, les SIM, relations et finances sur quelques exemples puis l'idempotence.
