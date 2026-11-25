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

## Audit follow-up, 24 November 2026 (plugin version remains 2.18.39)

Run the complete targeted follow-up from the source checkout, after installing the prerequisites above:

```bash
python tools/check-security-followup.py \
  --php /absolute/path/to/php \
  --glpi-source /absolute/path/to/glpi-source \
  --extra-autoload /absolute/path/to/uxconnector/tests/Security/tools/vendor/autoload.php \
  --chromium /absolute/path/to/chromium \
  --out /absolute/path/to/new-report-directory
```

The runner unsets GLPI_ROOT and uses only disposable fixtures. It checks the new scope inventory,
CLI command output, actual CommonDBTM inheritance, generic CRUD denial, IPv6/IPv4 fallback,
HMAC/replay on SQLite with numeric ID and unique fingerprint, and GLPI's real Migration SQL builder
through a recording adapter. It does **not** run a GLPI upgrade or an HTTP API session against MariaDB.
It also reruns the earlier CSP, credential/entity and RC13 regression suites, runtime lint and scoped
PHPStan level 5. The runner exits nonzero if any required check fails or times out.

The `legacy/` fixtures originate from the RC13 QA kit. The network assertion uses the new `streamUrls()`
list contract; the attachment fopen double follows its move into PinnedStreamOpener. The DNS-pin
negative control now returns an array to match that contract. No original security assertion was removed.
The five negative controls alter only temporary copies, then require the corresponding test to fail.
They are not an exhaustive mutation analysis.

PHP CS Fixer should be run sequentially with the repository configuration on changed PHP files. The
historical complete PHPUnit suite remains separate; do not interpret a passing targeted run as a green
complete suite. Final GLPI/MariaDB checks and same-version deployment instructions are in
`docs/SECURITY_2.18.39_FOLLOWUP.md`.
