# Validation Docker — UXConnector 2.13.0 / Zendesk

Les commandes ci-dessous sont à lancer **dans le conteneur GLPI 11** après copie du dossier `uxconnector` dans `plugins/`.

```bash
cd /var/www/html/glpi/plugins/uxconnector

php -v
php -m | grep -E 'dom|mbstring|SimpleXML|xmlwriter|tokenizer'

# Vérifier la version et le manifeste Zendesk
php -r 'require "vendor/autoload.php"; require "setup.php"; print_r(plugin_version_uxconnector());'
php -r '$j=json_decode(file_get_contents("resources/connectors/zendesk/connector.json"),true,512,JSON_THROW_ON_ERROR); echo $j["id"]," / ",$j["version"]," / jobs=",count($j["jobs"]),PHP_EOL;'

# Syntaxe PHP
find src tests front sql -name '*.php' -print0 | xargs -0 -n1 php -l
php -l setup.php
php -l hook.php

# Dépendances de développement si elles ne sont pas déjà présentes
composer install --no-interaction --prefer-dist
composer dump-autoload -o

# Style GLPI
php vendor/bin/phpcs --standard=GlpiStandard src setup.php hook.php front tests

# Analyse statique dans le vrai environnement GLPI
php vendor/bin/phpstan analyse -c phpstan.neon.dist --memory-limit=1G

# Tests unitaires
unset GLPI_ROOT
php vendor/bin/phpunit --testsuite Unit

# Tests d'intégration (adapter si GLPI_ROOT diffère)
export GLPI_ROOT=/var/www/html/glpi
php vendor/bin/phpunit --testsuite Integration
```

## Installation / mise à jour du schéma

Après remplacement du plugin 2.12.0 par 2.13.0, exécuter la mise à jour depuis GLPI ou la CLI GLPI afin que la table de checkpoint soit créée :

```bash
cd /var/www/html/glpi
php bin/console glpi:plugin:install uxconnector -f
# ou, si déjà installé et que votre procédure GLPI utilise la mise à jour globale :
php bin/console database:update
```

Contrôle SQL attendu :

```sql
SHOW TABLES LIKE 'glpi_plugin_uxconnector_sync_checkpoints';
DESCRIBE glpi_plugin_uxconnector_sync_checkpoints;
```

## Configuration Zendesk

Dans UXConnector :

- provider : `Zendesk Support`
- endpoint : `https://<sous-domaine>.zendesk.com`
- auth : `basic` avec secret `<email-agent>/token:<api_token>` **ou** `bearer_token`
- test path : `/api/v2/users/me.json`
- template recommandé pour une bascule progressive : `zendesk.tickets.active.inbound`

Le premier run utilise `start_time`. Une fois terminé sans erreur de record, `after_cursor` est stocké dans `glpi_plugin_uxconnector_sync_checkpoints`. Les exécutions suivantes utilisent `cursor` et ne repartent pas de l'historique.
