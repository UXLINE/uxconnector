# UXConnector 2.18.17 - Candidate de recette (15 octobre 2026)

Base : UXConnector **2.18.15** (dernière version dont les utilisateurs ont confirmé les tests Zendesk). La 2.18.16 candidate, non retenue, n'est **pas** utilisée comme base : le contrôle bloquant des champs Fields est conservé.

## Corrections du job GLPI 10 Télécoms

- **Ligne** : `Line.caller_name` reçoit le texte du champ Utilisateur / usage source ; aucun rapprochement avec `User`, aucun `users_id` alimenté.
- **Matériel** : recherche ou création `Phone` ou `NetworkEquipment`, puis tentative indépendante d'association à la ligne via `Item_Line`. Réexécution idempotente des liens.
- **Finances** : `Infocom.delivery_date` et `Infocom.value` sont écrits **sur le matériel** uniquement, jamais sur la ligne. Les dates/montants invalides génèrent un avertissement explicite.
- **Carte SIM** : le référentiel `DeviceSimcard` est résolu ou créé comme modèle générique explicitement nommé ; `Item_DeviceSimcard` reçoit ICCID (`serial`), PUK (protégé), ligne, lieu et matériel le cas échéant. Un ICCID est obligatoire ; protection contre l'association silencieuse d'un ICCID à une autre ligne.
- **Traçabilité** : motif stable et sûr par sous-opération `equipment`, `equipment_link`, `financial`, `simcard`. Aucune valeur PUK/ICCID ou montant dans les avertissements.
- **Recherche source** : tolérance supplémentaire sur les libellés N° SIM / ICCID et nom appelant en provenance des search options GLPI 10.
- **Fail-fast Fields** : les listes obligatoires (2 sur `Location`, 14 sur `Line`) sont **strictement préservées** avant toute écriture.

## Limites et qualification obligatoire

- Les tests hors ligne simulent les API GLPI ; ils ne prouvent pas les droits ni les créations réelles dans GLPI 11.0.11.
- La création du modèle SIM exige le droit de création sur `DeviceSimcard`; le PUK exige le droit GLPI `devicesimcard_pinpuk` approprié.
- Les identifiants d'entités GLPI et les doublons préexistants doivent faire l'objet d'une recette sur échantillon.
- Les champs source vides ou absents ne peuvent pas être reconstitués ; vérifier les noms de search options et les valeurs réellement remontées.
- Les mécanismes Zendesk 2.18.15 sont conservés, sans réécriture du job catalogue ou des tickets.
- Pas de migration spécifique des licences Microsoft dans cette livraison.
- Pas de publication ni d'homologation éditeur avant tests d'intégration et revue.

## Recette conseillée

1. Sauvegarde de GLPI et du plugin ; installation sur clone de test.
2. `sh tools/preflight-2.18.17.sh`, puis `./vendor/bin/phpunit -c phpunit.xml.dist` si les dépendances dev sont installées.
3. Contrôle avant job des 16 champs Fields cibles et des droits GLPI (dont PUK et informations financières).
4. Import contrôlé sur une ligne avec matériel, ICCID/PUK, date de remise et prix.
5. Vérifier : `glpi_lines.caller_name`, `glpi_items_lines`, `glpi_items_devicesimcards` et `glpi_infocoms` sur **le matériel** ; aucune `users_id` ajoutée.
6. Relancer le même échantillon : aucun doublon ; contrôler la rubrique « Failed items » après une erreur volontaire.
7. Vérifier un enregistrement sans matériel : création de la ligne et de la SIM avec lien à la ligne, avertissement financier si des montants sont présents.
