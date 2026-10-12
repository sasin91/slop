<?php
// Read from DB_HOST, DB_PORT, DB_USER, DB_PASSWORD and DB_DATABASE when the
// server sets them. With no database name, nothing is saved (Welcome_model).
$env = function (string $name, string $default): string {
    foreach ([getenv($name), $_SERVER[$name] ?? null, $_ENV[$name] ?? null] as $value) {
        if (is_string($value) && $value !== '') {
            return $value;
        }
    }
    return $default;
};
$databases = [
    'default' => [
        'host' => $env('DB_HOST', '127.0.0.1'),
        'port' => $env('DB_PORT', '3306'),
        'user' => $env('DB_USER', 'root'),
        'password' => $env('DB_PASSWORD', ''),
        'database' => $env('DB_DATABASE', '')
    ]
];
unset($env);
