# UXConnector 2.18.2 - testing notes

## Why the Integration bootstrap changed

GLPI 11 integration tests cannot be bootstrapped reliably by including only
`vendor/autoload.php` and `inc/includes.php`. That leaves core runtime state
uninitialized (database, config constants, test Kernel/session, plugin state).

UXConnector now follows the GLPI plugin test model:

- Unit tests: plugin Composer autoload only, no GLPI dependency.
- Integration tests: delegate to `<GLPI_ROOT>/tests/bootstrap.php`.
- Integration tests require a dedicated GLPI `testing` database and an active
  UXConnector plugin in that testing environment.
- Never run the Integration suite against the normal runtime/production DB.

## Unit and synthetic volume tests

```sh
cd /var/www/glpi/plugins/uxconnector
php tools/zendesk-volume-test.php 20000 100
php vendor/bin/phpunit -c phpunit.xml.dist --testsuite Unit
```

## Integration tests

Use a GLPI source/development tree that ships `tests/bootstrap.php`, prepare a
separate testing database with GLPI's CLI (`--env=testing`), then install and
enable UXConnector in that same testing environment.

Example (adapt DB settings to the dedicated test DB):

```sh
cd /var/www/glpi
php bin/console database:install --env=testing --force \
  --db-host=<db-host> --db-name=glpi_test --db-user=<test-user> \
  --db-password=<test-password> --no-interaction --no-telemetry

php bin/console plugin:install --env=testing uxconnector -u glpi --force
php bin/console plugin:enable --env=testing uxconnector

cd /var/www/glpi/plugins/uxconnector
GLPI_ROOT=/var/www/glpi php vendor/bin/phpunit -c phpunit.xml.dist --testsuite Integration
```

The Integration tests write test records and execute plugin migrations. Keep the
`testing` database disposable and isolated from any real GLPI data.
