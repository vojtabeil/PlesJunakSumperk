<?php
include('db.php');


if (isset($_COOKIE['admin_auth'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input_username = $_POST['username'] ?? '';
    $input_password = $_POST['password'] ?? '';

    $conn = new mysqli($servername, $username, $password, $database);

    if ($conn->connect_error) {
        $error = 'Chyba připojení k databázi.';
    } else {
        $stmt = $conn->prepare('SELECT user_name, password FROM admin WHERE user_name = ?');
        $stmt->bind_param('s', $input_username);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if ($user && password_verify($input_password, $user['password'])) {
            setcookie('admin_auth', $user['user_name'], time() + 86400, '/', '', false, true);
            $conn->close();
            header('Location: index.php');
            exit;
        } else {
            $error = 'Špatné uživatelské jméno nebo heslo.';
        }

        $stmt->close();
        $conn->close();
    }
}
?>

<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" type="text/css" href="style.css">

    <title>Přihlášení</title>
</head>

<body>
    <h2>Přihlášení do administrace</h2>

    <?php if ($error): ?>
        <p style="color: red;"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>

    <form method="post">
        <label>
            Uživatelské jméno:<br>
            <input type="text" name="username" required>
        </label><br><br>

        <label>
            Heslo:<br>
            <input type="password" name="password" required>
        </label><br><br>

        <button type="submit">Přihlásit</button>
    </form>
</body>

</html>