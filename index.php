<?php
declare(strict_types=1);

/**
 * Heat Liga — entry point.
 * Serves the web page and routes /api to the API.
 *
 * Run with the PHP built-in server:
 *   php -S 127.0.0.1:8000 index.php
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (basename(rtrim($path, '/')) === 'api') {
    require __DIR__ . '/api.php';
    return;
}

header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/index.html');
