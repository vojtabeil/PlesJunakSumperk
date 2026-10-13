<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>admin page</title>
    <link rel="stylesheet" type="text/css" href="style.css">
    <style>

    </style>

</head>

<body>

    <?php
    include('db.php');
    include('function.php');
    include "../mailSet.php";
    session_start();
    $conn = new mysqli($servername, $username, $password, $database);
    if ($conn->connect_error) {
        die("DB chyba: " . $conn->connect_error);
    }

    $conn = new mysqli($servername, $username, $password, $database);


    if (isset($_POST['name']) && isset($_POST['function'])) {
        $query_last_id = mysqli_query($conn, "SELECT MAX(id) AS last_id FROM zTicket");
        $row = mysqli_fetch_assoc($query_last_id);
        $last_id = $row['last_id'];
        $new_id = $last_id + 1;

        // Zpracování formuláře
        $name = $_POST['name'];
        $function = $_POST['function'];

        $query_insert = "INSERT INTO zTicket (id, name, `function`) VALUES ($new_id, '$name', '$function')";
        $result = mysqli_query($conn, $query_insert);

        if ($result) {
            echo "Záznam byl úspěšně přidán s ID: $new_id";
            header("location: index.php?zTicket=zTicket");
            exit();
        } else {
            echo "Chyba při přidávání záznamu: " . mysqli_error($conn);
        }
    }

    if (!isset($_COOKIE['admin_auth'])) {
        header('Location: login.php');
        exit;
    } else {

        if ($conn->connect_error) {
            die("Connection failed: " . $conn->connect_error);
        }

        echo "<form method='GET'><input type='submit' name='nezaplaceno' value='nezaplaceno'>
            <input type='submit' name='zaplaceno' value='zaplaceno' >
            <input type='submit' name='mista' value='mista'>
            <input type='submit' name='zTicket' value='zTicket'>
            <input type='submit' name='nastaveni' value='nastaveni'></form>";
        if (isset($_GET["nezaplaceno"])) {
            nezaplaceno($conn);
        }
        if (isset($_GET["zaplaceno"])) {
            zaplaceno($conn);
        }
        if (isset($_GET["zTicket"])) {
            zTicket($conn);
        }

        if (isset($_GET["mista"])) {
            editPlace($conn);
        }
        if (isset($_GET["nastaveni"])) {
            nastaveni($conn);
        }

        //edit nastaveni
        if (isset($_POST['uloz_nastaveni'])) {
            $sitPrice = filter_input(INPUT_POST, 'sitPrice', FILTER_VALIDATE_FLOAT);
            $standPrice = filter_input(INPUT_POST, 'standPrice', FILTER_VALIDATE_FLOAT);
            $sitTicket = filter_input(INPUT_POST, 'sitTicket', FILTER_VALIDATE_INT);
            $standTicket = filter_input(INPUT_POST, 'standTicket', FILTER_VALIDATE_INT);
            $maxTicket = filter_input(INPUT_POST, 'maxTicket', FILTER_VALIDATE_INT);
            $sender_mail = filter_input(INPUT_POST, 'sender_mail', FILTER_VALIDATE_EMAIL);
            $bankNumber = trim($_POST['bankNumber'] ?? '');
            $datePles = trim($_POST['datePles'] ?? '');
            $operation = filter_input(INPUT_POST, 'operation', FILTER_VALIDATE_INT);
            $startTime = trim($_POST['startTime'] ?? '');

            if (
                $sitPrice === false || $standPrice === false ||
                $sitTicket === false || $standTicket === false || $maxTicket === false ||
                !$sender_mail || $bankNumber === '' || $datePles === '' ||
                $operation === false || $startTime === ''
            ) {
                echo "<p style='color:red;'>Chyba: Některá pole jsou neplatná nebo prázdná.</p>";
            } else {
                $stmt = $conn->prepare("UPDATE settings SET 
            sitPrice = ?, standPrice = ?, sitTicket = ?, standTicket = ?, maxTicket = ?, 
            sender_mail = ?, bankNumber = ?, datePles = ?, operation = ?, startTime = ? 
            WHERE id = 1");
                if ($stmt) {
                    $stmt->bind_param(
                        'ddiiisssis',
                        $sitPrice,
                        $standPrice,
                        $sitTicket,
                        $standTicket,
                        $maxTicket,
                        $sender_mail,
                        $bankNumber,
                        $datePles,
                        $operation,
                        $startTime
                    );
                    if ($stmt->execute()) {
                        header("location: index.php?nastaveni=nastaveni");
                        exit();
                    } else {
                        echo "<p style='color:red;'>Chyba při ukládání: " . $stmt->error . "</p>";
                    }
                    $stmt->close();
                } else {
                    echo "<p style='color:red;'>Chyba: Nepodařilo se připravit dotaz.</p>";
                }
            }
        }


        //zobrazování jednotlivých sekcí
        //zaznam pro zTicket
        if (isset($_POST['name']) && isset($_POST['function'])) {
            $query_last_id = mysqli_query($conn, "SELECT MAX(id) AS last_id FROM zTicket");
            $row = mysqli_fetch_assoc($query_last_id);
            $last_id = $row['last_id'];
            $new_id = $last_id + 1;

            // Zpracování formuláře
            $name = $_POST['name'];
            $function = $_POST['function'];

            // Přidání záznamu do tabulky
            $query_insert = "INSERT INTO zTicket (id, name, `function`) VALUES ($new_id, '$name', '$function')";
            $result = mysqli_query($conn, $query_insert);

            if ($result) {
                echo "Záznam byl úspěšně přidán s ID: $new_id";
                header("location: index.php?zTicket=zTicket");
            } else {
                echo "Chyba při přidávání záznamu: " . mysqli_error($conn);
            }
        }
        //upravování jednotlivých objednávek
        if (isset($_GET["uprav"])) {
            $idEditUser = $_GET["uprav"];
            //todo
            editTicket($idEditUser);
        }


        //potvrzení platby

        if (isset($_GET["potvrzeno"]) && isset($_GET["id"])) {
            $id = $_GET["id"];
            $conn = new mysqli($servername, $username, $password, $database);

            $sql = "SELECT mail, name, price FROM ticket WHERE id = ?";
            $stmtTicket = $conn->prepare($sql);
            $stmtTicket->bind_param("i", $id);
            $stmtTicket->execute();
            $result = $stmtTicket->get_result();

            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $email = $row['mail'];
                $jmeno = $row['name'];
                $price = $row['price'];

                $sqlTotal = "SELECT COUNT(*) as total FROM place WHERE state = 'reserved' AND ticket_id = ?";
                $stmtPlaces = $conn->prepare($sqlTotal);
                $stmtPlaces->bind_param("i", $id);
                $stmtPlaces->execute();
                $rowTotal = $stmtPlaces->get_result()->fetch_assoc();
                $totalPlaces = $rowTotal['total'];

                $templateRes = $conn->query("SELECT message FROM infMessage WHERE position = 'potvrzeni' LIMIT 1");
                if (!$templateRes || $templateRes->num_rows === 0) {
                    die("Chybí šablona zprávy pro potvrzení platby.");
                }
                $template = $templateRes->fetch_assoc()['message'];

                $tvar_lic = ($totalPlaces > 1) ? "ů" : "u";
                $tvar_lisky = ($totalPlaces > 1) ? "ek" : "y";

                $message = str_replace(
                    ['{{jmeno}}', '{{pocet}}', '{{cena}}', '{{id}}', '{{tvar_lic}}', '{{tvar_lisky}}'],
                    [$jmeno, $totalPlaces, $price, $id, $tvar_lic, $tvar_lisky],
                    $template
                );

                $sqlT = "UPDATE ticket SET state = 'paid' WHERE id = ?";
                $stmtUpdate = $conn->prepare($sqlT);
                $stmtUpdate->bind_param("i", $id);
                if ($stmtUpdate->execute()) {
                    sendEmail($email, "Platba lístků na skautský ples byla potvrzena", $message);
                    header("location: index.php?nezaplaceno=nezaplaceno");
                    exit();
                } else {
                    echo "Chyba při aktualizaci stavu.";
                }
            } else {
                echo "Záznam s ID $id nebyl nalezen.";
            }
        }

        //storno nezaplacených rezervací
        if (isset($_GET["storno"]) && isset($_GET["id"])) {
            $conn = new mysqli($servername, $username, $password, $database);
            $id = $_GET["id"];
            $sql = "SELECT mail FROM ticket WHERE id = '$id'";

            // Provedení dotazu
            $result = $conn->query($sql);

            // Kontrola výsledku a získání hodnoty
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $email = $row['mail'];
                $mailHeaders = 'Content-Type: text/html; charset=utf-8' . "\r\n" . "From: Skautský ples Šumperk <ples@junak-sumperk.cz>";
                $message = "
                <h1>Rezervace na skautský ples byla zrušena</h1>
                <p>Dobrý den, musíme vás zklamat, ale vaše rezervace na skautský ples byla dnes z důvodu nezaplacení požadované ceny zrušena.<br> 
                Nic ale není ztraceno!<br>
                Pokud máte o ples zájem stačí si lístky rezervovat znovu a poslet peníze pod novým VS před vypršením termínu, kdy má být zaplacena.</p>

                <p>Lukáš Rozsíval<br>email: ples@junak-sumperk.cz</p> 

                <p>Junák - český skaut<br>
                Středisko Rudy Knotka Šumperk, z. s.<br>
                Nám. Jana Zajíce 11<br>
                787 01 Šumperk</p>";
                $subject = "Rezervace na skautský ples byla zrušena";
                $to = $email;
                $sqlT = "UPDATE ticket SET state = 'storno' WHERE id = '$id'";
                if ($conn->query($sqlT) !== TRUE) {
                    echo "alert('dojebalo se to1!!');";
                } else {
                    $sqlP = "UPDATE place SET state = 'free', ticket_id = NULL, reservedTime = NULL WHERE ticket_id = '$id' AND state = 'reserved'";
                    if ($conn->query($sqlP) !== TRUE) {
                        echo "";
                    } else {
                        sendEmail($to, $subject, $message);
                        header("location: index.php?nezaplaceno=nezaplaceno");
                        exit();
                    }
                }
            } else {
                echo "Záznam s ID $id nebyl nalezen.";
            }
        }
    }
    ?>
</body>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.save-trigger').forEach(span => {
            span.addEventListener('click', function() {
                const id = this.getAttribute('data-id');
                const xInput = document.querySelector(`.x-input[data-id="${id}"]`);
                const yInput = document.querySelector(`.y-input[data-id="${id}"]`);
                const statusEl = document.getElementById(`status-${id}`);

                if (!xInput || !yInput) return;

                const x = xInput.value.trim();
                const y = yInput.value.trim();

                if (x === '' || y === '') {
                    statusEl.textContent = '❌ Vyplňte pole';
                    statusEl.style.color = 'red';
                    return;
                }

                // Validace čísel (volitelné)
                if (isNaN(x) || isNaN(y)) {
                    statusEl.textContent = '❌ Čísla prosím';
                    statusEl.style.color = 'red';
                    return;
                }

                statusEl.textContent = 'Ukládám...';
                statusEl.style.color = 'orange';

                fetch('function.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'ajax_action=update_place_coordinates&id=' + encodeURIComponent(id) +
                            '&x=' + encodeURIComponent(x) +
                            '&y=' + encodeURIComponent(y)
                    })
                    .then(response => response.json())
                    .then(data => {
                        statusEl.textContent = data.ok ? '✅ OK' : '❌ Chyba';
                        statusEl.style.color = data.ok ? 'green' : 'red';
                    })
                    .catch(() => {
                        statusEl.textContent = '❌ Chyba';
                        statusEl.style.color = 'red';
                    });
            });
        });
    });
</script>

</html>