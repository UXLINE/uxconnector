<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// Isolated adapter tests, never a GLPI runtime or a production database.
if ($autoload = getenv('SECURITY_TEST_AUTOLOAD')) {
    require $autoload;
}
if ($core = getenv('GLPI_TEST_SOURCE')) {
    spl_autoload_register(static function (string $class) use ($core): void {
        $file = $core . '/src/' . str_replace('\\', '/', $class) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}

foreach (['READ' => 1, 'UPDATE' => 2, 'CREATE' => 4, 'DELETE' => 8, 'PURGE' => 16, 'ERROR' => 3] as $name => $value) {
    define($name, $value);
}

class CommonDBTM
{
    public static bool $allow = true;

    public function can($ID, int $right, ?array &$input = null): bool
    {
        return self::$allow;
    }
}

class Session
{
    public static array $messages = [];

    public static function addMessageAfterRedirect(string $message, ...$options): void
    {
        self::$messages[] = $message;
    }
}

class Html
{
    public static array $redirects = [];

    public static function redirect(string $url): void
    {
        self::$redirects[] = $url;
    }
}

function htmlescape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function __(string $text, ...$arguments): string
{
    return $text;
}
