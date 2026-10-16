<?php
// Router for the PHP built-in server of the portable version (scripts/run.ps1, copied from dev/portable/).
// Document root is web/www/; this emulates www/.htaccess (front controller) plus Adminer for this computer.

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');

// Adminer for the local database; with Start-LAN.cmd other devices get 404.
if ($path === '/adminer' || str_starts_with($path, '/adminer/')) {
    if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
        http_response_code(404);
        echo 'Not found';
        return true;
    }
    require __DIR__ . '/../runtime/adminer/adminer.php';
    return true;
}

// Existing files in web/www/ (built assets, images) are served directly; dotfiles never.
$www = realpath(__DIR__ . '/../web/www');
$file = realpath($www . $path);
if ($file !== false && is_file($file) && str_starts_with($file, $www . DIRECTORY_SEPARATOR)
    && !str_contains($path, '/.') && pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
    return false;
}

// Everything else goes to the Nette front controller.
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $www . DIRECTORY_SEPARATOR . 'index.php';
require $www . '/index.php';
return true;
