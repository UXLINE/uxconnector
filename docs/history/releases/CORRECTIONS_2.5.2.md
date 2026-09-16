# UXConnector 2.5.2 — Correctif du test d’authentification

- Ajout du champ `test_path` sur les configurations d’authentification.
- Le bouton **Test connection** envoie un GET authentifié sur ce chemin.
- Une réponse n’est réussie que si son code HTTP est compris entre 200 et 299.
- HTTP 401/403 ne sont plus présentés comme un succès et placent la configuration en erreur.
- Pour Jira Cloud : utiliser `/rest/api/3/project` comme chemin de test.
- Migration additive et idempotente de la colonne `test_path`.
- Les correctifs GLPI 11 précédents (CSRF, `find()`, suppression AuthConfig) sont conservés.
