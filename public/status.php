<?php
// Diagnostic page for the local environment. Available only when config 'debug' is true.

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

if (!(config()['debug'] ?? false)) {
    http_response_code(404);
    exit;
}

$db = config()['db'];
$extensions = ['pdo_mysql', 'mbstring', 'intl', 'openssl', 'curl'];
$dbError = null;
$dbVersion = null;
$tables = [];

try {
    $dbVersion = db()->query('SELECT VERSION()')->fetchColumn();
    foreach (db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $tables[$table] = (int) db()->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    }
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ples - local environment</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 720px; margin: 2rem auto; padding: 0 16px; color: #222; background: #fafafa; }
        .ok { color: #1a7f37; } .err { color: #c62828; }
        table { border-collapse: collapse; } td, th { padding: 4px 12px 4px 0; text-align: left; }
        code { background: #eee; padding: 1px 4px; border-radius: 3px; }
    </style>
</head>
<body>
    <h1>Local environment is running</h1>

    <h2>PHP</h2>
    <p>Version <strong><?= h(PHP_VERSION) ?></strong></p>
    <ul>
        <?php foreach ($extensions as $ext): ?>
            <li class="<?= extension_loaded($ext) ? 'ok' : 'err' ?>"><?= h($ext) ?></li>
        <?php endforeach; ?>
    </ul>

    <h2>Database</h2>
    <?php if ($dbError): ?>
        <p class="err">Connection failed: <?= h($dbError) ?></p>
    <?php else: ?>
        <p class="ok">Connected to <code><?= h($db['name']) ?></code> (<?= h($dbVersion) ?>)</p>
        <table>
            <tr><th>Table</th><th>Rows</th></tr>
            <?php foreach ($tables as $name => $count): ?>
                <tr><td><?= h($name) ?></td><td><?= $count ?></td></tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <h2>Links</h2>
    <ul>
        <li><a href="/">Reservation page</a></li>
        <li><a href="/adminer?server=127.0.0.1:<?= (int) $db['port'] ?>&amp;username=<?= h($db['user']) ?>&amp;db=<?= h($db['name']) ?>">Adminer</a> (password <code><?= h($db['pass']) ?></code>)</li>
        <li><a href="/original/">Recovered original site</a> (look only, no backend)</li>
    </ul>
</body>
</html>
