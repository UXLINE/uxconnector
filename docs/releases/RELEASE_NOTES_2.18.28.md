# UXConnector 2.18.28 : identification des champs Lieu Réseau et Licences

## Problème
Les modèles Réseau et Licences Microsoft utilisaient la table et le champ SQL pour identifier le nom complet du lieu. Or les options natives 1 (Nom complet) et 13 (Père) font toutes deux référence à glpi_locations.completename.

Le détecteur ne distinguait pas le lieu courant de son père et bloquait avant écriture :
Ambiguous source Location option for full_name (options 1, 13).

Les numéros sont des identifiants de champs de recherche API, pas des ID de lieux.

## Correction
- Demander /listSearchOptions/Location?raw=1 pour disposer des métadonnées de liaison que la réponse standard GLPI 10 retire.
- Exclure les champs du lieu parent ou d'une jointure lors de l'identification des champs natifs du lieu courant.
- Utiliser les métadonnées techniques, sans imposer les numéros 1 ou 13 ni dépendre des libellés traduits.
- Conserver le blocage si deux champs du lieu courant sont réellement indiscernables, et conserver le diagnostic exhaustif des champs Fields manquants.
- Préciser dans le message d'ambiguïté qu'il s'agit de champs API et non d'enregistrements.

Correction commune aux profils Réseau et Licences. Le profil Télécoms, les catégories et les correctifs PJ/captures 2.18.27 sont conservés. Aucun changement du schéma de base.

## Installation et recette
1. Arrêter proprement les jobs en cours et attendre leur arrêt avant remplacement.
2. Sauvegarder le dossier uxconnector et la base GLPI.
3. Remplacer le dossier du plugin par celui de l'archive, sans désinstaller le plugin et sans supprimer les tables ni les correspondances.
4. Vérifier la version 2.18.28 dans GLPI ; appliquer la mise à jour proposée si nécessaire.
5. Conserver connexions, modèles, field mappings, pagination et affectation d'entité actuels.
6. Relancer d'abord un petit périmètre du job Réseau ou Licences. Si le contrôle passe, le job pourra écrire : utiliser la préproduction ou un périmètre de recette.
7. Vérifier la création/mise à jour du matériel et de son lieu, ou de la licence et de ses affectations, puis le résultat d'une relance sans doublons.
8. Si une nouvelle erreur de champs manquants apparaît, conserver son message complet. Ce correctif ne contourne pas les autres prérequis métier.

Le diagnostic de connexion intitulé Telecom profile ne valide pas les modèles Réseau/Licences. Le test de connexion seul ne suffit pas : l'identification des champs est faite lors de l'exécution du bon modèle.

## Validation
- Erreur 1/13 reproduite avec la version 2.18.27 sur une fixture.
- 29 contrôles spécifiques passent : deux profils, ordre des options, identifiants renumérotés, libellés différents, métadonnées brutes, jointures, parent seul refusé, vrais doublons refusés, Fields ambigus refusés, requête raw et cache.
- Tous les contrôles du preflight fourni passent, y compris les 45 contrôles de PJ/captures.
- Syntaxe PHP des fichiers modifiés/ajoutés vérifiée sous PHP 8.3.6.
- Tests hors ligne avec schémas simulés, pas d'accès à l'API INDIGO réelle, pas de migration complète client exécutée.
- Suite PHPUnit non exécutée : dépendances vendor absentes.

Vérification du code GLPI source 10.0.28 :
https://github.com/glpi-project/glpi/blob/10.0.28/src/CommonTreeDropdown.php
https://github.com/glpi-project/glpi/blob/10.0.28/src/Api/API.php

Retour arrière : restaurer le dossier 2.18.27 sauvegardé.
