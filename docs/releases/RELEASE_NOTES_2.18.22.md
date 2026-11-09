# UXConnector 2.18.22 - GLPI 11 Fields search-option mapping (recette Datawise)

## Constat reproduit (Datawise, /listSearchOptions/Location)

La version 2.18.21 ne reconnaissait que 29/35 champs logiques, malgré la présence
réelle des 6 champs restants dans les options API. Le motif est une différence
de métadonnées GLPI 11 Fields : les listes déroulantes exposent une table
`glpi_plugin_fields_*dropdowns`, `field=completename` et un libellé préfixé par
le bloc `ndeligne - ...` ; le type de `Statut` et `Tarif équipements` diffère
de la source GLPI 10 INDIGO sur le banc Datawise.

## Corrections

- Extraction stricte du préfixe du bloc Fields depuis le libellé d'option
  (`ndeligne - ...`, `Inpark - ...`, `N° de ligne - ...`).
- Rapprochement additionnel à l'aide de la table technique dropdown déduite
  de `xml_node`, sans IDs numériques codés en dur.
- Correspondances exactes uniquement au sein du bloc attendu ; rejet d'un
  champ de bloc inconnu ou différent même avec un nom / identifiant homonyme.
- Conservation du fail-fast exhaustif source, du fail-fast Fields cible et de
  l'échec explicite sur correspondances multiples. Aucun assouplissement.
- Régression basée sur les **33 options Fields réelles** fournies par le
  diagnostic Datawise, plus 2 options natives (35/35, 0 manquant, 0 ambigu),
  tests d'absence, de doublons et de champs homonymes dans un autre bloc.
- Aucun changement du traitement métier INDIGO (SIM, lignes, équipements,
  Infocom), des profils Réseau/Microsoft, ni des flux Zendesk.

## Limitations et sécurité de la recette

- Validation **hors ligne** sur les métadonnées réellement remontées par
  Datawise : non encore validée avec un nouveau job exécuté de bout en bout.
- La liste `Équipements` du bac Datawise avait 0 valeur et `Tarif équipements`
  est un texte, tandis qu'INDIGO utilise une liste déroulante : poursuivre
  les vérifications des valeurs métier, du matériel et d'Infocom.
- Les problèmes de confiance TLS du conteneur Docker restent à corriger par
  installation d'une autorité de certification approuvée (ne pas désactiver TLS).
- Pas d'installation chez INDIGO avant qualification complète du flux.

## Commandes de recette

`docker exec -it glpi-data-glpi-1 sh -c "cd /var/www/glpi/plugins/uxconnector && sh tools/preflight-2.18.22.sh"`

Puis connexion Datawise > Inspect source Fields : 35/35, 0 MISSING,
0 AMBIGUOUS attendus. Vérifier les champs obligatoires du GLPI 11 cible
avant un job pilote #22. Laisser le doublon de job #23 désactivé.
