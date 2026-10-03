<?php
// Router for the PHP built-in server - used only for local development (start.cmd).
// Document root is www/; this emulates www/.htaccess (front controller) plus dev-only tools.

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');

// Adminer for managing the local database.
if ($path === '/adminer' || str_starts_with($path, '/adminer/')) {
    require __DIR__ . '/../.tools/adminer/adminer.php';
    return true;
}

// Static copy of the original site (without its PHP backend) for comparing the look.
if ($path === '/original' || str_starts_with($path, '/original/')) {
    $base = realpath(__DIR__ . '/../original');
    $relative = substr($path, strlen('/original'));
    if ($relative === '' || $relative === '/') {
        $relative = '/index.rendered.html';
    }
    $file = realpath($base . $relative);
    if ($file === false || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file)) {
        http_response_code(404);
        echo 'Not found';
        return true;
    }
    $types = ['html' => 'text/html; charset=UTF-8', 'css' => 'text/css', 'js' => 'text/javascript', 'svg' => 'image/svg+xml'];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    readfile($file);
    return true;
}

// Existing files in www/ (built assets, images) are served directly; dotfiles never.
$www = realpath(__DIR__ . '/../www');
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
