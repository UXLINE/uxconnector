# UXConnector 2.18.20 - Candidate de recette

## Architecture

Trois interactions planifiées distinctes dans le catalogue **GLPI REST v1** :

1. **INDIGO Télécoms** : `glpi_rest_v1.indigo.telecom.locations.normalize` - compatible avec les jobs existants (traitement conservé de la 2.18.19).
2. **INDIGO Réseau** : `glpi_rest_v1.indigo.network.locations.normalize` - conversion des pseudo-`Location` GLPI 10 en sites natifs et `NetworkEquipment` GLPI 11.
3. **INDIGO Microsoft** : `glpi_rest_v1.indigo.microsoft.licences.normalize` - produit natif `Software` et pool `SoftwareLicense` déjà préparés sur GLPI 11, puis affectations nominatives détaillées dans `glpi_plugin_uxconnector_licenseassignments`.

Chaque interaction doit utiliser **sa propre connexion GLPI 10**. Les checkpoints, planifications, pauses, historiques et verrous d'exécution restent ceux de `SyncJob`, isolés par identifiant de job.

## Préparation de GLPI 11

- Sauvegarder GLPI et la base avant la mise à jour du plugin.
- Effectuer la mise à jour du plugin via l'administration GLPI afin que la migration de schéma crée la table `glpi_plugin_uxconnector_licenseassignments` ; il est inutile de désinstaller le plugin.
- Installer et activer Fields, puis créer la structure cible avant de démarrer les jobs.
- **Réseau** : le contrôle bloquant exige sur `Location` : **IdBaseParcs, Début Exploitation** ; sur `NetworkEquipment` : **Entité Inpark, Code DA, Code DA Comptable, Code ETS, Code DB, Code NA, Code NB, Date de mise en service, Date ventilation, Date retour Stock, Montant Mensuel, Déjà Amorti**.
- **Télécoms** : contrôle préalable Fields préservé (2 champs Lieu, 14 champs Ligne).
- **Microsoft** : créer préalablement un logiciel et un pool de licence GLPI 11 par produit, avec une quantité acquise explicitement validée. Si plusieurs pools existent pour un même logiciel, le job refuse une attribution ambiguë. Les 9 champs de licence individuels et les 7 champs Inpark sont stockés dans la nouvelle table dédiée, par source GLPI 10 + identifiant source. Ils ne sont pas copiés dans `Location` et ne sont pas fusionnés au niveau du pool.

## Gestion Microsoft : limite métier explicite

Les enregistrements de GLPI 10 représentent des **affectations**, pas une preuve du nombre de licences acquises. le job **ne crée pas de pool ni ne renseigne `SoftwareLicense.number`**. Il exige un pool préexistant de quantité explicitement validée (une quantité à zéro est refusée). Ce choix empêche notamment qu’un zéro soit affiché comme « illimité » dans GLPI. Les affectations individuelles sont consultables sous **UXConnector → Microsoft licence assignments** ; elles ne créent pas automatiquement des `User` ni des relations natives `SoftwareLicense_User`, faute d'identifiants utilisateurs fiables et de capacité d'achat qualifiée.

## Qualité des données et protections

- La découverte des champs source s'appuie sur le catalogue REST GLPI ; aucun ID Fields numérique issu d'un export YAML n'est codé en dur.
- Le contrôle préalable bloquant affiche l'ensemble des champs sources introuvables et les champs Fields cibles manquants, regroupés par objet.
- Refus des correspondances ambiguës et des dates ou montants impossibles à convertir. Aucune substitution d'ID GLPI 10 par un ID GLPI 11.
- Écriture des objets standards exclusivement via les classes GLPI, et conservation des affectations Microsoft dans une table du plugin créée par sa migration.
- Les exceptions par objet sont consignées dans les incidents d'exécution sans exposer les bénéficiaires, montants ou jetons.
- Les objets de même nom ou de même numéro de série ambigu doivent être corrigés, jamais rapprochés arbitrairement.

## Limites et recette obligatoire

- Cette version est une **candidate de recette**, pas une release éditeur officiellement validée.
- Le modèle réseau et les relations natives doivent être vérifiés sur GLPI 11.0.11 avec les données réelles.
- Les choix GLPI 10 de type *dropdown* doivent être vérifiés en lecture REST : si l'API retourne seulement un identifiant de liste et non son libellé, il faut résoudre la liste avant tout import monétaire ou métier. Ne pas considérer un identifiant de liste comme un prix.
- Les référentiels de produits Microsoft et le nombre acquis doivent être arbitrés, sans assimilation abusive des affectations à des achats.
- Test de rejouabilité sans doublons requis pour chacun des trois jobs ; aucun changement sur Zendesk ne doit être accepté sans ses tests de régression.

## Installation / tests

```sh
sh tools/preflight-2.18.20.sh
./vendor/bin/phpunit -c phpunit.xml.dist --do-not-cache-result
```

La suite PHPUnit nécessite l'installation isolée des dépendances Composer de développement. Le préflight et les tests sans accès réseau peuvent être effectués hors INDIGO ; les API GLPI 10 et la CMDB GLPI 11 sont indispensables à la validation de bout en bout.
