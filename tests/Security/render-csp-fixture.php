<?php

declare(strict_types=1);

require getenv('SECURITY_TEST_AUTOLOAD') ?: throw new RuntimeException('Provide a Twig autoloader in SECURITY_TEST_AUTOLOAD.');

$root = dirname(__DIR__, 2);
$loader = new Twig\Loader\FilesystemLoader($root . '/templates');
$loader->addPath($root . '/templates', 'uxconnector');
$twig = new Twig\Environment($loader, ['autoescape' => 'html']);
$twig->addFunction(new Twig\TwigFunction('__', static fn (string $text, string $domain = '') => $text . ' « QA & "quote" <text> »'));
$twig->addFunction(new Twig\TwigFunction('csrf_token', static fn () => 'fictitious-token'));
$twig->addFunction(new Twig\TwigFunction('_x', static fn (string $context, string $text) => $text));
$confirmations = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/templates')) as $file) {
    if (!$file->isFile() || !str_ends_with($file->getFilename(), '.twig')) {
        continue;
    }
    $text = file_get_contents($file->getPathname());
    if (preg_match('/\bon[a-z]+\s*=|javascript\s*:|<script\b/i', $text)) {
        throw new RuntimeException('Inline executable content in ' . $file->getFilename());
    }
    $twig->compileSource(new Twig\Source($text, substr($file->getPathname(), strlen($root . '/templates/'))));
    preg_match_all('/data-uxconnector-confirm="(.*?)"/s', $text, $matches);
    foreach ($matches[0] as $attribute) {
        $confirmations[] = $twig->createTemplate($attribute)->render([]);
    }
}
if (count($confirmations) !== 11) {
    throw new RuntimeException('Expected eleven migrated confirmations.');
}
echo '<!doctype html><meta charset="utf-8"><title>UXConnector CSP fixture</title>';
echo '<iframe name="sink" hidden></iframe>';
foreach ($confirmations as $id => $attribute) {
    echo '<form id="confirm-' . $id . '" method="post" target="sink" action="/submitted">'
        . '<input name="entry"><button name="action" value="' . $id . '" ' . $attribute . '>Confirm</button></form>';
}
echo <<<'HTML'
<form id="run" class="uxconnector-run-form" method="post" target="sink" action="/submitted">
    <button name="run_job" value="1" data-uxconnector-confirm="Run?">Run</button>
</form>
<form id="credentials" method="post" target="sink" action="/submitted">
    <button name="credential_action" value="reveal">Reveal</button>
    <button name="credential_action" value="rotate" data-uxconnector-confirm="Rotate?">Rotate</button>
</form>
<select data-uxconnector-connector-selector><option value="custom">Custom</option><option value="a&amp;b=1">Other</option></select>
<select id="uxconnector-auth-method"><option value="bearer_token">Bearer</option><option value="glpi_rest_v1">GLPI</option></select>
<div id="uxconnector-generic-credential">Generic</div><details id="uxconnector-glpi-rest-credentials">Tokens</details>
<form id="uxconnector-bulk-jobs" method="post" target="sink" action="/submitted">
    <div id="uxconnector-bulk-selected-inputs"></div><button type="submit" name="bulk_activate" value="1" disabled>Bulk</button>
</form>
<input type="checkbox" id="uxconnector-select-all-jobs"><input type="checkbox" id="uxconnector-select-all-jobs-toolbar">
<span id="uxconnector-selected-count" data-uxconnector-selected-label="selected"></span>
<input type="checkbox" class="uxconnector-job-checkbox" value="28"><input type="checkbox" class="uxconnector-job-checkbox" value="31">
<script nonce="qa-nonce" src="/js/uxconnector.js"></script>
HTML;
