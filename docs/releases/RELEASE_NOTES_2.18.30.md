# UXConnector 2.18.30 candidate

Date : 28 octobre 2026. Base : 2.18.29 candidate.

## Problème corrigé

Le précontrôle des migrations réseau attendait des libellés Fields canoniques. Le GLPI cible INDIGO possède déjà ces données dans des champs dont les libellés sont verrouillés et préfixés par `Code Inpark`. La version 2.18.29 signalait donc à tort que ces sept champs étaient absents.

La version 2.18.30 reconnaît ces correspondances exactes, uniquement pour `NetworkEquipment` :

| Libellé logique utilisé par le job | Libellé du champ existant accepté |
| --- | --- |
| Entité Inpark | Code Inpark Entité |
| Code DA | Code Inpark Code DA |
| Code DA Comptable | Code Inpark Code DA Comptable |
| Code ETS | Code Inpark Code ETS |
| Code DB | Code Inpark Code DB |
| Code NA | Code Inpark Code NA |
| Code NB | Code Inpark Code NB |

Le précontrôle et l'écriture utilisent le même résolveur. Le nom technique est lu dans le schéma Fields actif, jamais deviné ou fixé à un numéro de champ. Les anciennes installations utilisant les libellés canoniques restent prises en charge.

## Protections conservées

- Aucun renommage, création ou suppression de champ, de bloc ou de valeur de configuration.
- Pas de modification des données source ni des mappings enregistrés.
- Seuls les blocs actifs associés au type cible et les champs actifs sans lecture seule sont utilisables.
- Aucun rapprochement approximatif de libellés et aucun repli vers un autre type d'objet.
- Si le champ canonique et son alias existent simultanément, l'import est refusé comme ambigu.
- Deux mappings visant le même attribut physique sont refusés avant écriture.
- L'éditeur continue d'afficher les vrais libellés des champs existants.
- L'écriture des valeurs passe toujours par les objets natifs générés par Fields.
- Les correctifs 1/13, PJ/captures, workers CLI et emails de la version 2.18.29 sont inclus. Leurs prérequis et limites restent décrits dans RELEASE_NOTES_2.18.29.md.

## Installation

1. Terminer ou arrêter les jobs et workers, puis sauvegarder le plugin et la base.
2. Remplacer le répertoire `uxconnector` par celui du paquet, sans désinstaller le plugin.
3. Appliquer la mise à jour proposée dans GLPI. Aucune table ni colonne n'est ajoutée par ce correctif.
4. Conserver les champs du bloc `INDIGO Réseau` avec leurs libellés et noms techniques actuels.
5. Relancer un petit import réseau et vérifier les valeurs Inpark sur un équipement importé avant un passage complet.

## Validation

Le scénario de test reproduit le message exact de l'instance INDIGO avec le code 2.18.29 et passe avec le correctif. Il couvre 26 contrôles : sept valeurs vers les colonnes réellement exposées, ID équipement et bloc stables, absence de modification du schéma, mise à jour, rejeu sans doublon, champs inactifs/en lecture seule, mauvais type d'objet, libellés inconnus et ambiguïtés.

Le preflight complet et la syntaxe PHP sont vérifiés dans l'environnement de développement. Les tests utilisent des doubles de base de données et des objets GLPI/Fields ; aucune connexion au serveur INDIGO n'a été effectuée. La recette sur la cible reste nécessaire. Les dépendances PHPUnit, PHPStan et les outils Composer de style ne sont pas installés et ne sont pas déclarés exécutés.
