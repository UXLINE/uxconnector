"""Reproduce the 2.18.39 audit follow-up checks on isolated fixtures (no GLPI runtime database)."""
import argparse
import json
import os
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--glpi-source', type=Path, required=True)
    parser.add_argument('--extra-autoload', type=Path, required=True)
    parser.add_argument('--chromium', required=True)
    parser.add_argument('--out', type=Path, required=True)
    args = parser.parse_args()
    args.out.mkdir(parents=True, exist_ok=False)
    env = dict(os.environ, GLPI_TEST_SOURCE=str(args.glpi_source.resolve()),
               SECURITY_TEST_AUTOLOAD=str(args.extra_autoload.resolve()), PHP=args.php,
               CHROMIUM_EXECUTABLE=args.chromium)
    env.pop('GLPI_ROOT', None)
    results = {}

    def run(name, command, timeout=150):
        try:
            process = subprocess.run(command, cwd=ROOT, env=env, capture_output=True, text=True, timeout=timeout)
            code, output = process.returncode, process.stdout + process.stderr
        except subprocess.TimeoutExpired:
            code, output = 2, 'Check timed out; no success recorded.\n'
        (args.out / (name + '.log')).write_text(output)
        results[name] = {'exit': code, 'command': [str(x) for x in command]}
        print(f'{name}: {code}', flush=True)

    run('php-version', [args.php, '--version'])
    run('phpunit-security', [args.php, 'vendor/bin/phpunit', '-c', 'phpunit.security.xml'])
    for name in ['core_item_access', 'webhook_replay_sqlite']:
        run(name, [args.php, f'tests/Security/{name}.php'])
    run('dualstack-tls', [sys.executable, 'tests/Security/test_dualstack_tls.py'])
    run('csp-browser', [sys.executable, 'tests/Security/test_csp_browser.py'])
    legacy = ROOT / 'tests/Security/legacy'
    for scenario in ['sortant', 'api-webhook']:
        run(scenario, [args.php, str(legacy / 'test-security.php'), str(ROOT), scenario])
    for scenario in ['stop', 'migration', 'retirement', 'rc12-network', 'rc12-writer',
                     'rc12-attachments', 'rc12-retry', 'rc13-asset-identity']:
        run(scenario, [args.php, str(legacy / f'test-{scenario}.php'), str(ROOT)])
    run('rc12-https', [sys.executable, str(legacy / 'test-rc12-https.py'), args.php, str(ROOT)])
    run('negative-controls', [sys.executable, str(legacy / 'test-rc12-mutations.py'), args.php, str(ROOT)])
    paths = [ROOT / 'setup.php', ROOT / 'hook.php']
    for directory in ['src', 'front', 'inc', 'sql']:
        paths.extend(sorted((ROOT / directory).rglob('*.php')))
    errors = []
    for path in paths:
        process = subprocess.run([args.php, '-l', str(path)], capture_output=True, text=True, timeout=20)
        if process.returncode:
            errors.append(process.stdout + process.stderr)
    (args.out / 'lint.log').write_text(f'{len(paths)} runtime PHP files checked. Errors: {len(errors)}\n' + '\n'.join(errors))
    results['lint'] = {'exit': int(bool(errors)), 'files': len(paths)}
    paths = [
        'Infrastructure/Engine/Http/Security/PinnedStreamOpener.php',
        'Infrastructure/Engine/Http/Security/PinnedHttpDestination.php',
        'Infrastructure/Sync/Migration/LegacyJobScopeReport.php',
        'Infrastructure/Sync/Console/ReviewActiveJobScopesCommand.php',
        'Infrastructure/Sync/Persistence/SyncJobItem.php',
        'Infrastructure/Sync/Persistence/SyncExecutionItem.php',
        'Infrastructure/Sync/Persistence/ObjectLinkItem.php',
        'Infrastructure/Engine/Http/Authentication/OAuth2TokenClient.php',
        'Infrastructure/Engine/Http/Authentication/GlpiRestV1AuthenticationStrategy.php',
        'Infrastructure/Engine/Http/GenericHttpEngine.php',
        'Infrastructure/Zendesk/ZendeskAttachmentDownloader.php',
        'Application/Engine/Service/BindLegacyCredentialsService.php',
        'Application/Sync/Service/JobEntityAccess.php',
        'Domain/Engine/Security/OriginBoundCredential.php',
    ]
    config = args.out.resolve() / 'phpstan.neon'
    # JSON double-quoted strings are also valid NEON strings; quote filesystem paths.
    quote = lambda p: json.dumps(str(p))
    config.write_text('parameters:\n    level: 5\n    treatPhpDocTypesAsCertain: false\n'
                      + '    tmpDir: ' + quote(args.out.resolve() / 'phpstan-cache') + '\n    paths:\n'
                      + ''.join('        - ' + quote(ROOT / 'src' / p) + '\n' for p in paths)
                      + '    bootstrapFiles:\n        - ' + quote(ROOT / 'vendor/autoload.php')
                      + '\n    scanDirectories:\n        - ' + quote(args.glpi_source.resolve() / 'src')
                      + '\n    scanFiles:\n        - ' + quote(ROOT / 'setup.php') + '\n')
    run('phpstan', [args.php, 'vendor/bin/phpstan', 'analyse', '--no-progress', '--debug',
                    '-c', str(config), '--memory-limit=1G'])
    (args.out / 'results.json').write_text(json.dumps(results, indent=2))
    raise SystemExit(int(any(value['exit'] for value in results.values())))


if __name__ == '__main__':
    main()
