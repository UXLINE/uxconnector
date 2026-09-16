# UXConnector 2.8.1 — Correctif parcours Connections

Cette version corrige le parcours observé sur GLPI 11 après migration 2.8.0.

## Problèmes corrigés

1. Une ancienne configuration Jira apparaissait comme `Custom REST` et son bouton **Test** utilisait encore le chemin historique `/`, qui renvoyait HTTP 401 sur le gateway Atlassian.
2. La fiche Connection ne permettait pas d’éditer le nom, l’endpoint, la ressource de test, la méthode d’authentification ou le secret.
3. Depuis la liste, **Test** exécutait bien le test mais redirigeait vers la même fiche que **Manage**, ce qui rendait les deux actions visuellement indiscernables.
4. Les actions d’activation/désactivation/suppression n’étaient pas disponibles directement depuis la liste.

## Comportement 2.8.1

- **Nom** et **Manage** ouvrent la fiche de la Connection.
- **Test** depuis la liste exécute le test et revient à la liste avec le message de résultat.
- **Edit** ouvre le formulaire d’édition de la Connection.
- Le secret existant n’est jamais réaffiché. Laisser le champ vide le conserve ; saisir une valeur le remplace.
- Associer `Jira Cloud` à une ancienne Connection applique automatiquement le `test_path_hint` du manifest Jira (`/rest/api/3/project/search?maxResults=1`) et remet la Connection en Brouillon.
- Après un test HTTP 2xx, la Connection passe à l’état `testing` et le bouton **Activate** devient disponible.
- Une Connection active peut être **Disable** puis réactivée après un nouveau test si ses paramètres ont changé.
- Les interactions standards du connecteur apparaissent sur la même fiche ; l’éditeur REST complet reste l’option Advanced/custom.

## Aucun changement de schéma

2.8.1 est un correctif applicatif. Aucune colonne ou table supplémentaire n’est créée par rapport à 2.8.0. Le script d’installation/migration existant reste idempotent.
