# UXConnector 2.16.1 — correctif GLPI High-Level Assets

## Correctif

La 2.16.0 introduisait le MatchField synthétique `asset_identity` pour réconcilier un asset GLPI distant selon plusieurs clés possibles (`uuid`, `serial`, `otherserial`, puis `name` pour certains types).

L'invariant de domaine `SyncJob` exigeait toutefois encore que le MatchField corresponde littéralement au `targetField` d'un `FieldMapping`. `asset_identity` étant une stratégie virtuelle et non une colonne GLPI, la création d'un job échouait avec :

> MatchField "asset_identity" does not correspond to the targetField of any configured FieldMapping.

La 2.16.1 autorise désormais `asset_identity` dès lors qu'au moins un champ d'identité réel est mappé : `uuid`, `serial`, `otherserial` ou `name`.

Le garde-fou reste strict : un job `asset_identity` sans aucun de ces champs est refusé.
