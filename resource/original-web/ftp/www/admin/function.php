<?php
include('db.php');
    $conn = new mysqli($servername, $username, $password, $database);

function editTicket($id)
{
    global $conn;
    $sql = "SELECT id, name, mail, phone, price FROM ticket WHERE id = '$id'";
    $result = $conn->query($sql);
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        echo "<form method='POST'>
            <input type='hidden' name='id' value='" . $row['id'] . "'>
            Name: <input type='text' name='name' value='" . $row['name'] . "'><br>
            Mail: <input type='text' name='mail' value='" . $row['mail'] . "'><br>
            Phone: <input type='text' name='phone' value='" . $row['phone'] . "'><br>
            Price: <input type='text' name='price' value='" . $row['price'] . "'><br>
            <input type='submit' name='ulozit' value='Uložit změny'>
        
            </form>";
    } else {
        echo "Záznam s ID $id nebyl nalezen.";
    }
}



?>

<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function login_admin($conn, $username, $password)
{
    $stmt = $conn->prepare("SELECT password FROM admin WHERE user_name = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows === 1) {
        $row = $result->fetch_assoc();
        if (password_verify($password, $row["password"])) {
            $_SESSION["admin"] = $username;
            return ["ok" => true];
        } else {
            return ["ok" => false, "error" => "Nesprávné heslo"];
        }
    }
    return ["ok" => false, "error" => "Uživatel nenalezen"];
}

function render_login_form($error = null)
{
    echo "<div class='forms'>
            <form method='POST'>
                <fieldset>
                    <legend>Login</legend>";

    if ($error) {
        echo "<p style='color:red;'>$error</p>";
    }

    echo "  <table>
                    <tr>
                        <td><label for='admin_name'>Uživatelské jméno:</label></td>
                        <td><input type='text' name='admin_name' id='admin_name' required></td>
                    </tr>
                    <tr>
                        <td><label for='heslo'>Heslo:</label></td>
                        <td><input type='password' name='heslo' id='heslo' required></td>
                    </tr>
                </table>
                <br><button type='submit'>Přihlásit</button>
            </fieldset>
            </form>
        </div>";
}


function zTicket($conn)
{
    echo '<form method="post" class="zTicket">
        <label for="name">Name:</label>
        <input type="text" name="name" required>
        
        <label for="function">Function:</label>
        <input type="text" name="function" required><br>
        
        <input type="submit" value="Přidat záznam">
    </form>';
    $ask2 = mysqli_query($conn, "SELECT id, name, `function` FROM zTicket");

    if (!$ask2) {
        die("Error in SQL query: " . mysqli_error($conn));
    }
    echo '<p class="info">
                    Zde jsou Vaše lístky. Lístky si prosím uložte do mobilu nebo vytiskněte a vystřiněte na papír. Lístkem se prokážete u vstupu a s každým lístkem také můžete vyhrát nějakou cenu z tomboly ;-)
                </p>';

    while ($uzivatel2 = mysqli_fetch_array($ask2)) {
        echo '
                  
                  
                          
                          
                           <div class="ticket">
                          <div class="number">
                              <p><img src="../ticket/log.png" alt="logo"></p
                              <p><strong>#' . $uzivatel2['id'] . '</strong></p>
                          </div>
                  
                          <div class="content">
                              <div class="details">
                                  <div class="nz">14. šumperský <br><span class="nz2">SKAUTSKÝ PLES</span></div><br>
                                  <table class="fr">
                                      <tr>
                                          <td>' . $uzivatel2['name'] . ' - ' . $uzivatel2['function'] . '</td>
                                      </tr>
                                      <tr>
                                          <td>7. 2. 2026 v 19:00</td>
                                      </tr>
                                      <tr>
                                          <td>Sokolovna, Nový Malín</td>
                                      </tr>
                                  </table>
                              </div>
                          </div>
                      </div>';
    }
}

function zaplaceno($conn)
{
    $sql = "SELECT id, name, mail, price FROM ticket WHERE state = 'paid'";
    $result = $conn->query($sql);

    $tickets = array();
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $tickets[] = $row;
        }
    }

    $redCount = 0;
    $greenCount = 0;
    $standredCount = 0;
    $sitredCount = 0;

    foreach ($tickets as $ticket) {
        $idT = $ticket["id"];
        $sqlt = "SELECT id, usedTime, stand FROM place WHERE ticket_id = '$idT'";
        $resultT = $conn->query($sqlt);
        if ($resultT->num_rows > 0) {
            while ($rowPlace = $resultT->fetch_assoc()) {
                if ($rowPlace['stand'] == 1) {
                        $standredCount++;
                    }else {
                        $sitredCount++;
                    }
                if ($rowPlace['usedTime'] !== null) {
                    $redCount++;
                } else {
                    $greenCount++;
                }
            }
        }
    }

    $allCount = $greenCount + $redCount;
    echo "<p>Počet hostů co přišli: <strong>$redCount / $greenCount</strong> Celkový počet: $allCount</p>";
    echo "<p>Počet stání: <strong>$standredCount</strong>, Počet sedění: <strong>$sitredCount</strong></p>";

    echo "<table border='1'>
            <tr class='first'>
                <td>ID</td>
                <td>Name</td>
                <td>Mail</td>
                <td>Price</td>
                <td>Ticket</td>
                <td>popřípadné zrušení</td>
            </tr>";

    foreach ($tickets as $ticket) {
        echo "<tr>
                <td>" . $ticket["id"] . "</td>
                <td>" . $ticket["name"] . "</td>
                <td>" . $ticket["mail"] . "</td>
                <td>" . $ticket["price"] . "</td>
                <td>";
        $sumprice += $ticket["price"];
        $idT = $ticket["id"];
        $sqlt = "SELECT id, usedTime FROM place WHERE ticket_id = '$idT'";
        $resultT = $conn->query($sqlt);
        if ($resultT->num_rows > 0) {
            while ($rowPlace = $resultT->fetch_assoc()) {
                if ($rowPlace['usedTime'] !== null) {
                    echo "<span style='color: red;'>" . $rowPlace["id"] . "</span>, ";
                } else {
                    echo "<span style='color: lightgreen;'>" . $rowPlace["id"] . "</span>, ";
                }
            }
        } else {
            echo "0 results";
        }

        echo "</td>";
            // do budoucna pro admina
           /*echo"<td>  <form method='GET'><input type='hidden' name='id' value='$idT'>
                <input type='submit' name='storno' value='Storno'></form></td>";*/
            echo "</tr>";
    }

    echo "</table>";
    echo " Celkem za lístky" . $sumprice . "Kč";
}

function nezaplaceno($conn)
{

    $sql = "SELECT id, name, mail, price, phone FROM ticket WHERE state = 'reserved'";
    $result = $conn->query($sql);
    echo "<table>
        <tr class='first'><td >ID<td>Name<td>Mail<td>Price<td>Ticket<td>Čas rezrevace<td>Potvrzení<td>Storno</tr>";
    // Výpis informací o tiketech
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $idT = $row["id"];
            echo "<tr><td><a href='?uprav=" . $idT . "'>ID: </a>" . $row["id"] . "<td>Name: " . $row["name"] . "<td>Mail: " . $row["mail"] . "&nbsp;&nbsp;&nbsp; tel:" . $row["phone"] . "<td> Price: " . $row["price"] . "";
            $sqlt = "SELECT id, reservedTime FROM place WHERE ticket_id = '$idT'";
            $resultT = $conn->query($sqlt);
            echo "<td>";
            if ($resultT->num_rows > 0) {
                while ($row = $resultT->fetch_assoc()) {
                    echo $row["id"] . ", ";
                    $casRez = $row["reservedTime"];
                }
            } else {
                echo "0 results";
            }
            $formattedDate = date('d. m. 20y', $casRez);
            $expire_date = date('d. m. 20y', $casRez + 604800);
            echo "<td>" . $formattedDate . "&nbsp;&nbsp;exp." . $expire_date;
            echo "<td>  <form method='GET'><input type='hidden' name='id' value='$idT'>
                <input type='submit' name='potvrzeno' value='zaplaceno'></form></td>
                <td>  <form method='GET'><input type='hidden' name='id' value='$idT'>
                <input type='submit' name='storno' value='Storno'></form></td>";

            echo "</tr>";
        }
    } else {
        echo "0 results";
    }



    echo "</table>";
}

function editPlace($conn)
{
    if ($conn->connect_error) {
        die("Připojení selhalo: " . $conn->connect_error);
    }

    $result = $conn->query("SELECT id, x, y FROM place WHERE stand = 0 ORDER BY id");

    if (!$result) {
        echo "<p>Chyba při načítání dat: " . $conn->error . "</p>";
        return;
    }

    if ($result->num_rows === 0) {
        echo "<p>Žádná místa se stand = 0 nebyla nalezena.</p>";
        return;
    }

    echo '<table border="1" cellpadding="8" cellspacing="0" id="placesTable">';
    echo '<thead><tr><th>ID</th><th>X</th><th>Y</th><th>Akce</th><th>Stav</th></tr></thead>';
    echo '<tbody>';

    while ($row = $result->fetch_assoc()) {
        $id = (int)$row['id'];
        $x = htmlspecialchars($row['x']);
        $y = htmlspecialchars($row['y']);

        echo "<tr id='row-$id'>";
        echo "<td>$id</td>";
        echo "<td><input type='number' step='0.01' class='x-input' data-id='$id' value='$x' style='width:80px;' required></td>";
        echo "<td><input type='number' step='0.01' class='y-input' data-id='$id' value='$y' style='width:80px;' required></td>";
        echo "<td><span class='save-trigger' data-id='$id' style='cursor:pointer; color:blue; text-decoration:underline;'>Uložit</span></td>";
        echo "<td><span class='status' id='status-$id'></span></td>";
        echo "</tr>";
    }

    echo '</tbody></table>';
}

function nastaveni($conn) {
    // Načteme nastavení (předpokládáme, že existuje řádek s id = 1)
    $result = $conn->query("SELECT * FROM settings WHERE id = 1");
    if (!$result || $result->num_rows === 0) {
        echo "Chyba: Nastavení nebylo nalezeno v databázi.";
        return;
    }
    $s = $result->fetch_assoc();
    ?>
    <h2>Nastavení plesu</h2>
    <form method="POST">
        <label>sitPrice: <input type="number" step="0.01" name="sitPrice" value="<?= htmlspecialchars($s['sitPrice']) ?>" required></label><br>
        <label>standPrice: <input type="number" step="0.01" name="standPrice" value="<?= htmlspecialchars($s['standPrice']) ?>" required></label><br>
        <label>sitTicket: <input type="number" name="sitTicket" value="<?= (int)$s['sitTicket'] ?>" required></label><br>
        <label>standTicket: <input type="number" name="standTicket" value="<?= (int)$s['standTicket'] ?>" required></label><br>
        <label>maxTicket: <input type="number" name="maxTicket" value="<?= (int)$s['maxTicket'] ?>" required></label><br>
        <label>sender_mail: <input type="email" name="sender_mail" value="<?= htmlspecialchars($s['sender_mail']) ?>" required></label><br>
        <label>bankNumber: <input type="text" name="bankNumber" value="<?= htmlspecialchars($s['bankNumber']) ?>" required></label><br>
        <label>datePles: <input type="text" name="datePles" value="<?= htmlspecialchars($s['datePles']) ?>" required></label><br>
        <label>operation: <input type="number" name="operation" value="<?= (int)$s['operation'] ?>" required></label><br>
        <label>startTime: <input type="text" name="startTime" value="<?= htmlspecialchars($s['startTime']) ?>" required></label><br>
        <input type="submit" name="uloz_nastaveni" value="Uložit nastavení">
    </form>
    <?php
}

//aktualizace míst přes AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'update_place_coordinates') {
    if (!isset($_SESSION['admin']) && !isset($_COOKIE['admin_auth'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Neoprávněný přístup']);
        exit;
    }

    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    $x  = filter_input(INPUT_POST, 'x', FILTER_VALIDATE_FLOAT);
    $y  = filter_input(INPUT_POST, 'y', FILTER_VALIDATE_FLOAT);

    if ($id === false || $id <= 0 || $x === false || $y === false) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Neplatná data']);
        exit;
    }

    $stmt = $conn->prepare("UPDATE place SET x = ?, y = ? WHERE id = ?");
    if (!$stmt) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Chyba dotazu']);
        exit;
    }

    $stmt->bind_param('ddi', $x, $y, $id);
    $success = $stmt->execute();
    $stmt->close();

    header('Content-Type: application/json; charset=utf-8');
    if ($success) {
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Nepodařilo se uložit']);
    }

    exit; 
}
?>