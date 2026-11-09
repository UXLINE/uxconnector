# UXConnector 2.18.18 - Candidate corrective INDIGO

## Périmètre

Correction incrémentale de la 2.18.17. Le traitement Zendesk (catalogue de
sujets, hiérarchie et tickets/delta) et les mécanismes de création des SIM,
liaison matériel–ligne, gestion financière du matériel et `caller_name`
sont conservés.

## Nouveaux contrôles

1. Vérification *avant toute écriture* des champs sources attendus dans
   `/listSearchOptions/Location` (et contrôle Fields cible préexistant).
   En cas d'absence, le job est bloqué avec la liste précise des champs.
2. Vérification de la présence des colonnes `forcedisplay` dans chaque
   réponse API de ligne téléphonique. Une colonne absente produit un warning
   `SOURCE_OPTION_NOT_RETURNED` consultable dans les incidents de l'exécution.
3. Une colonne transmise mais vide est distinguée de l'absence de colonne.
   La valeur vide n'est pas inventée ni considérée automatiquement comme erreur.
4. Conversion plus stricte des dates et des montants pour l'alimentation de
   `Infocom` **du matériel** (jamais de la ligne).

## À savoir

- Ce correctif ne reconstitue pas un nom de l'appelant, un matériel ou un montant
  dont la source est réellement vide. Si les colonnes sont reconnues, mais les
  valeurs restent vides, examiner un enregistrement source anonymisé.
- L'interface de détail dépend de l'identifiant d'exécution et du repository
  d'incidents disponibles lors du traitement.
- Les règles de rapprochement du matériel et la classification des libellés
  `OTD` sont héritées et doivent encore être validées en recette client.
- Aucun identifiant ICCID, PUK, tarif ou nom d'utilisateur n'est inclus dans
  les messages de nouveaux avertissements.

## Recette

```sh
sh tools/preflight-2.18.18.sh
./vendor/bin/phpunit -c phpunit.xml.dist --do-not-cache-result
```

Puis tester des lignes avec colonnes source absentes, colonnes vides,
présentes et renseignées, date et tarif, rattachement matériel et SIM,
ainsi que le rejeu sans doublons. Ne pas déployer en production avant
validation réelle sur GLPI 11 et source GLPI 10 autorisée.
