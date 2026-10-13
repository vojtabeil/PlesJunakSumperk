<?php

/**
 * Loads secrets from the ".env" file next to this script into the environment.
 * ".env" is git-ignored; ".env.example" lists the keys with placeholder values.
 * Values already set in the real environment win over the file.
 */

function loadEnv(string $file): void
{
    if (!is_file($file)) {
        return;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if (preg_match('/^(["\'])(.*)\1$/', $value, $m)) {
            $value = $m[2];
        }
        if (getenv($name) === false) {
            putenv("$name=$value");
        }
    }
}

/**
 * Returns a required secret. The exception names the key, never its value.
 */
function env(string $name): string
{
    $value = getenv($name);
    if ($value === false) {
        throw new RuntimeException("Missing environment variable $name (see .env.example).");
    }
    return $value;
}

loadEnv(__DIR__ . '/.env');
