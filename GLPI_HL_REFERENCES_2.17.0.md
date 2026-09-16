# UXConnector 2.17.0 — Enrichissement des assets GLPI High-Level

Cette version prolonge la consolidation GLPI → GLPI validée en 2.16.x.

## Ajouts

- correction définitive des chemins des six jobs GLPI High-Level : `/api.php/Assets/...` ;
- enrichissement automatique après écriture de chaque asset ;
- résolution/création dans le GLPI cible de :
  - entité ;
  - statut ;
  - fabricant ;
  - lieu ;
  - type d'actif ;
  - modèle d'actif ;
- rattachement des références cibles à l'asset importé ;
- aucune copie des identifiants numériques du GLPI source ;
- conservation de l'idempotence des assets via ObjectLink ;
- refus d'un rapprochement de référentiel ambigu (plusieurs lignes cibles de même nom dans le même périmètre).

## Fonctionnement

La High-Level API renvoie les références sous forme d'objets imbriqués, par exemple :

```json
{
  "manufacturer": {"id": 9, "name": "HP"},
  "location": {"id": 87, "name": "Nantes"},
  "type": {"id": 1, "name": "UC"},
  "model": {"id": 1, "name": "COMPAQ 8300 ELITE"}
}
```

L'id source n'est jamais recopié. UXConnector cherche/crée la valeur par nom dans le GLPI cible et écrit uniquement l'id cible obtenu.

## Déploiement sur une instance déjà alimentée

Il n'est pas nécessaire de supprimer les assets déjà importés. Après mise à jour en 2.17.0, relancer les jobs existants : ObjectLink retrouve les mêmes assets et la phase d'enrichissement rattache les référentiels.

Les utilisateurs et groupes ne sont pas inclus dans cette version afin de ne pas préempter la stratégie d'identité Entra/SCIM prévue pour la cible.

Les relations entre assets (ordinateur ↔ écran / imprimante / périphérique / téléphone) restent la phase suivante.
