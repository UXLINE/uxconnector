<?php

declare(strict_types=1);

use GlpiPlugin\Uxconnector\Presentation\Copilot\OpenApiDocumentFactory;

header('Content-Type: application/json; charset=utf-8');

$scheme = 'http';

$forwardedProto = trim(
    explode(
        ',',
        (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''),
    )[0],
);

if (in_array($forwardedProto, ['http', 'https'], true)) {
    $scheme = $forwardedProto;
} elseif (!empty($_SERVER['HTTPS'])
    && $_SERVER['HTTPS'] !== 'off'
) {
    $scheme = 'https';
}

$host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

echo json_encode(
    (new OpenApiDocumentFactory())->create(
        $scheme . '://' . $host,
    ),
    JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
);
