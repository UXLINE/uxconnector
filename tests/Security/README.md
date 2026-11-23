# Security regression suite

Run only on disposable local fixtures. This suite does not bootstrap GLPI or connect to a GLPI database. Do not set `GLPI_ROOT` for it.

Prerequisites: PHP 8.3+ for PHPUnit 11 (executed with PHP 8.4), PDO SQLite, sodium, DOM and mbstring; Python 3 and Playwright/Chromium for the CSP scenario. The plugin runtime still requires PHP 8.2+.

```bash
composer install --no-interaction
composer install --working-dir=tests/Security/tools --no-interaction
git clone --depth 1 --branch 11.0.0 https://github.com/glpi-project/glpi.git ../glpi-security-source
export GLPI_TEST_SOURCE="$(cd ../glpi-security-source && pwd)"
export SECURITY_TEST_AUTOLOAD="$PWD/tests/Security/tools/vendor/autoload.php"
php vendor/bin/phpunit -c phpunit.security.xml
```

`GLPI_TEST_SOURCE` is a source checkout used only for `GLPIKey`, `DBmysql` and the real query builder. Its database configuration is not loaded. Without the sources and `thecodingmachine/safe`, the tests using these components are explicitly skipped.

Browser scenario:

```bash
python -m pip install playwright==1.62.0
python -m playwright install chromium
python tests/Security/test_csp_browser.py
```

Set `PHP` or `CHROMIUM_EXECUTABLE` to an absolute executable path when needed. The server binds only to loopback on an ephemeral port. It uses fictional data, captures only fixture submissions, and shuts down after the test. The test compiles all Twig templates, checks the eleven migrated confirmation expressions, and runs the shipped JavaScript under a nonce-only CSP.

The main historical PHPUnit suite is separate and contains pre-existing stale test doubles. See `docs/SECURITY_2.18.39.md` for the exact scope and remaining live-instance verification.
