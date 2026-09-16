# GLPI 11 boot / PSR-4 status

Corrections de compatibilité désormais intégrées à la version 2.5.1 :

- namespace technique `GlpiPlugin\Uxconnector\`, cohérent entre les classes,
  `composer.json` et l'autoload minimal de test ;
- `plugin_version_uxconnector()` autonome pour la phase de découverte du plugin ;
- contrôles de prérequis/configuration autonomes pour la même raison ;
- `hook.php` racine minimal ;
- `vendor/autoload.php` conservé uniquement comme chargeur PSR-4 minimal pour
  les tests autonomes lorsque les dépendances Composer ne sont pas installées.

Les quatre entrées `front/*.php` sont encore conservées pour compatibilité et
migration progressive. Elles ne chargent plus manuellement `inc/includes.php`
dans le flux web GLPI 11.

## Correctifs runtime GLPI 11 ajoutés

- suppression de la seconde validation CSRF dans les contrôleurs
  `AuthConfigController` et `SyncJobController` : le Kernel GLPI 11 valide
  déjà les POST avant le chargement des points d'entrée legacy et le token
  ne doit pas être consommé une seconde fois ;
- conservation de `_glpi_csrf_token` dans tous les formulaires Twig ;
- correction des repositories `AuthConfig`, `SyncJob` et `SyncExecution` :
  `CommonDBTM::find()` retourne déjà un tableau sous GLPI 11, donc
  `iterator_to_array()` ne doit pas être appliqué à son résultat.


## 2026-08-18 — Suppression des configurations d'authentification

- Ajout d'une action **Supprimer** dans la colonne Actions pour les profils disposant du droit PURGE.
- La suppression est permanente et supprime également le secret chiffré associé.
- Protection référentielle : une configuration encore utilisée par un job de synchronisation ne peut pas être supprimée ; le plugin demande d'abord de supprimer ou reconfigurer les jobs dépendants.
- Le POST reste protégé par le contrôle CSRF natif GLPI 11 (pas de seconde validation dans le contrôleur).
