#!/bin/sh
set -eu

cd "$(dirname "$0")/.."

echo "== UXConnector 2.18.3 preflight =="
echo "[1/3] Zendesk synthetic volume (20,000 tickets)"
php tools/zendesk-volume-test.php 20000 100

echo "[2/3] Unit suite"
php vendor/bin/phpunit -c phpunit.xml.dist --testsuite Unit

echo "[3/3] Zendesk offline regression suite"
env -u GLPI_ROOT php vendor/bin/phpunit -c phpunit.xml.dist --testsuite ZendeskOffline

echo "== PRE-FLIGHT OK =="
