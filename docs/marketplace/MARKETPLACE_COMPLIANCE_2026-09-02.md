# Rapport de conformité Marketplace — uxconnector

Date: 2026-09-02

## Score

- Avant corrections: **84/100**
- Après corrections: **91/100**
- Statut: **Bon niveau sécurité — release encore en état dev**

## Corrections effectuées

- Synchronisation du XML Marketplace de 2.10.2 vers la version réelle 2.12.0.
- Suppression de toutes les copies de sauvegarde `*.pre-*`, `.bak`, `.orig` susceptibles d’élargir inutilement la surface de revue/exposition.
- Conservation des endpoints M2M stateless protégés par clés dédiées.

## Points restant à valider / corriger

- Passer `<state>dev</state>` à `stable` seulement après recette de release; renseigner `__PUBLIC_RELEASE_URL__`; une migration progressive des `front/` vers Controllers GLPI 11 reste recommandée mais n’est pas une exigence Marketplace explicite si les permissions sont correctement appliquées.

## Référentiel utilisé

- GLPI Developer Documentation — Plugin development tutorial / Marketplace.
- GLPI Developer Documentation — Controllers (GLPI 11).
- GLPI Developer Documentation — Database / Migration.
- GLPI 11 upgrade guide — Query Builder et requêtes web.

## Validation locale réalisée

- Syntaxe PHP: **OK** sur tous les fichiers PHP du package corrigé.
- `composer.json`: **JSON valide**.
- XML Marketplace présent: **XML syntaxiquement valide** lorsqu'applicable.

> L'approbation finale Marketplace/Cloud reste une décision de Teclib'. Le Marketplace Cloud fait l'objet d'une revue sécurité plus stricte.

## Correctif Composer QA — 2026-09-02
- `dealerdirect/phpcodesniffer-composer-installer` explicitement autorisé dans `config.allow-plugins` pour permettre l’installation contrôlée du standard PHPCS.
