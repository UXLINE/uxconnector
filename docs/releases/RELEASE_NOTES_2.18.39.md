# UXConnector 2.18.39

Version stable du 25 novembre 2026 pour **GLPI >=11.0.0 et <12.0.0**, **PHP >=8.2**. Elle réunit les corrections de sécurité et les compléments de qualité apportés après la RC13. La recette de la livraison corrigée a été confirmée par le mainteneur avant cette publication.

## Corrections livrées

- Les écritures génériques des connexions via l’API GLPI sont refusées. Les secrets hérités sont liés à leur origine et à leur méthode d’authentification ; une liaison incompatible interdit leur réutilisation.
- Les actions sur une connexion contrôlent toutes les entités ciblées par ses jobs, y compris les jobs en pause. Les assignations de licences sont filtrées selon l’entité de la licence GLPI.
- Les jobs, exécutions et liens internes ne sont plus exposés comme objets CRUD génériques par l’API. Les écrans et services du plugin restent disponibles avec leurs contrôles d’accès.
- Les confirmations et interactions JavaScript sont externalisées pour fonctionner avec une CSP restrictive.
- Le transport stream essaie plusieurs adresses déjà approuvées avec un délai partagé, en conservant la validation TLS et le nom du serveur. Les écritures HTTP métier ne sont pas automatiquement réémises par ce repli.
- La table anti-rejeu utilise une clé primaire numérique `id` et conserve l’unicité de `fingerprint`. La migration ne purge pas les réservations existantes.
- La commande `plugins:uxconnector:review-active-job-scopes` inventorie les périmètres des jobs actifs. Elle ne reconstitue pas l’identité historique de leurs créateurs.
- Le bouton de révélation du bearer webhook est retiré ; le webhook reste signé en HMAC. Les contrôles Fields, mappings, checkpoints et liens de rapprochement sont conservés.

## Mise à jour

1. Sauvegarder ensemble la base, le plugin et `config/glpicrypt.key`. Suspendre les workers et émetteurs webhook pendant la mise à niveau.
2. Installer l’archive **`uxconnector-2.18.39.tar.gz`**. Elle contient le dossier racine `uxconnector/`. Utiliser cette pièce jointe, pas l’archive automatique des sources GitHub.
3. Si une livraison précédente porte déjà le numéro **2.18.39**, relancer explicitement `plugin:install uxconnector --force` avec un compte GLPI administrateur valide. Ne pas désinstaller le plugin.
4. Après réussite de l’installation, activer le plugin **avant** d’exécuter `plugins:uxconnector:review-active-job-scopes` : GLPI découvre la commande lorsque le plugin est actif. Les traitements restent suspendus pendant ce contrôle.
5. Vérifier les périmètres et la connexion avant reprise. Les émetteurs anciens utilisant seulement un bearer doivent être adaptés à la signature HMAC. Les secrets déjà migrés en RC13 ne sont pas régénérés par cette publication.

La [procédure détaillée](https://github.com/UXLINE/uxconnector/blob/production/docs/SECURITY_2.18.39_FOLLOWUP.md) fournit les commandes, les conséquences et les limites connues. Un retour arrière requiert un état cohérent code/base/clé.

## Distribution et vérification

- Archive : `uxconnector-2.18.39.tar.gz`.
- Empreinte : `uxconnector-2.18.39.tar.gz.sha256`, jointe à cette release.
- [XML brut de production](https://raw.githubusercontent.com/UXLINE/uxconnector/refs/heads/production/uxconnector.xml).
- Branche : `production` ; tag : `v2.18.39`.

Le code PHP, les vues et le JavaScript sont identiques à l’archive corrigée du 24 novembre. Les métadonnées de publication et la documentation ont été actualisées ; l’empreinte de l’archive complète change en conséquence.

Les preuves locales de sécurité et leurs limites sont documentées dans le dépôt. La suite générale héritée n’est pas annoncée entièrement verte. La publication GitHub et l’autorisation du Marketplace privé par TECLIB restent distinctes.
