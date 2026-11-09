# UXConnector 2.18.14 - candidate de recette

Base immuable : UXConnector 2.18.13 candidate. Les versions précédentes ne sont pas modifiées.

## Fonctionnel

- Objet cible **Carte SIM (composant)** (`Item_DeviceSimcard`) ajouté aux interactions REST avancées.
- Correspondances natives autorisées : `serial`, `otherserial`, `pin`, `pin2`, `puk`, `puk2`, `msin`, `devicesimcards_id`, `lines_id`, `locations_id`, `states_id`, `users_id`, `itemtype`, `items_id`.
- Champs Fields actifs de la cible affichés sous la forme `fields:<libellé exact>`; cette notation n'est pas un nom SQL. Le writer vérifie l'existence du conteneur/champ cible actif avant l'écriture.
- Réutilisation du `GlpiTargetFieldsWriter` existant, avec écriture des valeurs après création / mise à jour des objets natifs.
- Nouvelle transformation de champ `link:<type_objet_externe>` : résout, via les ObjectLink déjà enregistrés pour **la même connexion**, l'ID cible. Par exemple `link:glpi_rest_v1_location_telecom_line` pour renseigner `lines_id` à partir de l'ID d'ancien lieu source.
- Réconciliation des SIM par **ICCID / serial** et ObjectLink. Référence au catalogue `DeviceSimcard` obligatoire ; aucun catalogue ou type d'objet inventé automatiquement.

## Sécurité

- Maintien des listes d'écriture natives, avec refus des champs SQL sensibles (ID primaire, entité pilotée séparément, etc.).
- Vérification de l'entité explicite du job SIM ; vérification de l'existence et de l'entité des références GLPI 11 ; refus de liaison arbitraire à un autre type d'équipement.
- Aucun ID distant interprété automatiquement comme ID cible ; les transformations ObjectLink vérifient le type cible avant écriture.
- Refus des clés Fields inexistantes/ambiguës et interdiction de création automatique de schéma.
- Masquage PUK/PIN/ICCID dans les instantanés d'incident ; suppression de l'instantané brut pour les jobs de carte SIM.
- Les protections préexistantes (SSRF, credentials, CSRF, droits GLPI, retry/backoff, journalisation) sont préservées.

## Conditions de configuration

1. Utiliser un GLPI 11 disposant du composant `Item_DeviceSimcard` et d'un modèle de carte SIM natif déjà créé. `devicesimcards_id` doit identifier **ce modèle dans GLPI 11** (pas dans GLPI 10).
2. L'interaction SIM exige une **entité cible explicite** (stratégie fixe ou résolue) et la clé `serial` comme champ de rapprochement.
3. Pour lier une SIM à la ligne déjà migrée depuis un ancien lieu, définir `lines_id` avec comme source le même ID d'ancien lieu, et `link:glpi_rest_v1_location_telecom_line` comme transformation. Ne pas réimporter le numéro d'ID GLPI 10 directement comme ID GLPI 11.
4. Les champs Fields doivent être **créés et actifs** en amont ; pour chaque ligne de mapping, saisir `fields:Libellé exact` comme champ cible. Les champs sont proposés dynamiquement dans les interfaces d'ajout/modification.
5. Réaliser un essai à un seul enregistrement ; vérifier ICCID, PUK, modèle SIM, ligne, appareil, champs Fields puis rejouer pour confirmer l'idempotence.

## Limites / validation

- Une interaction cible un seul objet : les trois jobs GLPI 10 → ligne / équipement / SIM restent distincts. Aucune création automatique d'un pool de licences Microsoft ou de l'objet d'affectation métier n'est incluse.
- L'association à un matériel reste possible par référence GLPI 11 connue ou par ObjectLink déjà enregistré avec le même AuthConfig et le type de destination correct ; l'import spécialisé Télécoms ne crée pas encore de lien ObjectLink équipement.
- Les erreurs Fields après écriture native peuvent laisser l'objet natif créé : les ObjectLink et l'idempotence permettent une correction puis une relance, à vérifier en recette.
- Pas de test d'intégration réel GLPI 11/Fields/Docker INDIGO exécuté pour cette archive ; fournir les résultats PHPUnit et PHPStan avant publication, puis recette sur copie de données.
