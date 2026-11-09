# UXConnector 2.18.19 - Candidate de recette

## Portée
Correctif ciblé sur la découverte des champs Fields source du job GLPI REST v1 / INDIGO Télécoms. Les trois exports YAML ont été analysés uniquement pour identifier les conventions techniques ; ils **ne sont pas embarqués dans l’archive**. Le diagnostic préventif des champs manquants est conservé.

## Particularités confirmées
- Télécoms : 15 champs Inpark + 18 champs N° de ligne, tous initialement rattachés à Location.
- Réseau : 15 champs Inpark + 11 champs Équipements, rattachés à Location.
- Microsoft : 7 champs Inpark + 9 champs Licences, rattachés à Location.
- Les IDs YAML des champs et des options de liste ne sont pas les IDs de recherche REST.
- Les listes déroulantes `Tarif équipements` et `Montant HT` doivent être converties par **libellé** avant tout calcul financier. Le fichier YAML ne contient pas les valeurs par enregistrement, uniquement la configuration des listes.

## Non inclus / limites
Ce correctif ne crée pas les jobs Réseau/Microsoft, ne corrige pas les valeurs de liste retournées en tant qu'IDs si la REST API n'expose pas les libellés, et ne garantit pas les liens téléphone-ligne ni l'alimentation financière. Ces points nécessitent une recette sur l’API réelle.

## Recette
1. Installer en préproduction sauvegardée, conserver un historique des jobs.
2. `sh tools/preflight-2.18.19.sh`, puis PHPUnit complet.
3. Relancer un job Télécoms sur échantillon, relever tout champ signalé comme manquant ou ambigu.
4. Valider sur une ligne : `caller_name`, numéro de série, tarif, date, relation matériel et SIM.
5. Rejouer sans doublons.

La version n'est pas une release éditeur qualifiée tant que la recette GLPI 11 et les contrôles externes ne sont pas achevés.
