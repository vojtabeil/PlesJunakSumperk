<!DOCTYPE html>
<html lang="en">
<!--NEPOUŽÍVÁ SE POUŽÍVÁ SE NA SKENOVÁNÍ LÍSTKŮ TETS.PHP!-->
<!--********************************************************************************************************************************-->
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Formulář</title>
</head>

<body>

    <?php
    include('../db.php');
    session_start();
    $conn = new mysqli($servername, $username, $password, $database);

    if (isset($_POST["admin_name"]) && isset($_POST["heslo"])) {
        $admin = $_POST["admin_name"];
        $ask = mysqli_query($conn, "SELECT password FROM admin WHERE user_name= '$admin'");
        if ($ask) {
            if (mysqli_num_rows($ask) == 1) {
                $uzivatel = mysqli_fetch_array($ask);
                if (password_verify($_POST["heslo"], $uzivatel["password"])) {
                    $_SESSION["admin"] = $admin;
                } else {
                
                }
            }
        }
    }

    if (!isset($_SESSION["admin"])) {
    ?>
        <div class="forms">
            <form method='POST'>
                <fieldset>
                    <legend>Login</legend>
                    <table>
                        <tr>
                            <td><label for='admin_name'>uživatelské jméno</label></td>
                            <td><input type='text' name='admin_name' id='admin_name' required></td>
                        </tr>
                        <tr>
                            <td><label for='heslo'>Heslo:</label></td>
                            <td><input type='password' name='heslo' id='heslo' required></td>
                        </tr>
                    </table>

                    <br>
                    <br>
                    <button type="submit">přihlásit</button>
                </fieldset>
            </form>
        </div>
    <?php
    } else {
        if (isset($_POST["checkValue"]) && isset($_POST["emailValue"])) {
            $checkValue = $_POST["checkValue"];
            $emailValue = $_POST["emailValue"];
           
            $currentUnixTime = time();

            // SQL dotaz pro aktualizaci hodnoty sloupce usedTime
            $sql = "UPDATE place SET usedTime = $currentUnixTime WHERE id = $checkValue";

            // Spuštění dotazu
            if ($conn->query($sql) === TRUE) {
                echo "Hodnota usedTime byla úspěšně aktualizována.";
                header("Location: {$_SERVER['REQUEST_URI']}");
                exit;
            } else {
                echo "Chyba při aktualizaci hodnoty usedTime: " . $conn->error;
            }
        }
        if (isset($_POST["checkValueD"]) && isset($_POST["emailValueD"])) {
            $checkValue = $_POST["checkValueD"];
            $emailValue = $_POST["emailValueD"];

            $sql = "UPDATE place SET usedTime = NULL WHERE id = $checkValue";

            // Spuštění dotazu
            if ($conn->query($sql) === TRUE) {
                echo "Hodnota usedTime byla úspěšně aktualizována.";
               /* header("Location: {$_SERVER['REQUEST_URI']}");
                exit;*/
            } else {
                echo "Chyba při aktualizaci hodnoty usedTime: " . $conn->error;
            }
        }
        // Zpracování odeslaného formuláře
        /*if ($_SERVER["REQUEST_METHOD"] == "POST") {
            // Zde můžete zpracovat data z formuláře
            // Například:
            $checkValue = htmlspecialchars($_GET['check'] ?? '');
            $emailValue = htmlspecialchars($_GET['email'] ?? '');

            // Zde můžete provést další operace s hodnotami
            echo "Odeslaný formulář:<br>";
            echo "Check: $checkValue<br>";
            echo "Email: $emailValue<br>";
        } else {*/
        // Zobrazení formuláře  
        $checkValue = htmlspecialchars($_GET['check'] ?? '');
        $emailValue = htmlspecialchars($_GET['email'] ?? '');

        //získání id mailu
        $sql = "SELECT id FROM ticket WHERE mail = '$emailValue'";
        $result = $conn->query($sql);
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $ticketId = $row['id'];
            //echo "ID záznamu s e-mailem je: $ticketId";
        } else {
            //echo "Záznam s e-mailem nebyl nalezen.";
        }


        $usedT = true;
        $sql = "SELECT ticket_id, usedTime FROM place WHERE id = '$checkValue'";
        $result = $conn->query($sql);
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $ticketIdPl = $row['ticket_id'];
            $usedTime = $row['usedTime'];

            if ($usedTime !== null) {
                // Něco je ve sloupci usedTime, nastavíme usedT na false
                $usedT = false;
                //echo $usedTime;
            } else {
            }
        } else {
            //echo "Záznam s e-mailem nebyl nalezen.";
        }
        date_default_timezone_set('Europe/Prague');
        //echo $usedTime;
        if ($ticketId == $ticketIdPl) {
            if ($usedT == true) {
                echo "<body style='background-color: lightgreen;'><p>lístek:" . $checkValue . "</p>";
                echo "<p>mail:" . $emailValue . "</p>";
                echo '<form action="' . $emailValue . '" method="post">
                    <input type="hidden" name="checkValue" value="' . $checkValue . '">
                    <input type="hidden" name="emailValue" value="' . $emailValue . '">
                    <button type="submit">Přišel</button></form></body>';
            } else {
                echo "<body style='background-color: red;'><p>lístek:" . $checkValue . "</p>";
                echo "<p>mail:" . $emailValue . "</p>";
                echo '<form action="index.php" method="post">
                    <input type="hidden" name="checkValueD" value="' . $checkValue . '">
                    <input type="hidden" name="emailValueD" value="' . $emailValue . '">
                    <p>Návštěvní tu byl ' . date('Y-m-d H:i:s', $usedTime) . '<br>';
                if ($_SESSION["admin"] == "pospa") {
                    echo '<button type="submit">Přišel</button></form></body>';
                }else{
                    echo'V případě nedorozumění zavolejte Honzu (731 493 190)';
                }
            }
        } else {
            echo "<p>Lístek je neplatný</p>";
        }
        /*
    echo '<form action="index.php" method="post">';
    echo '  <label for="check">Check:</label>';
    echo '  <input type="text" name="check" id="check" value="' . htmlspecialchars($_GET['check'] ?? '') . '"><br>';
    echo '  <label for="email">Email:</label>';
    echo '  <input type="email" name="email" id="email" value="' . htmlspecialchars($_GET['email'] ?? '') . '"><br>';
    echo '  <input type="submit" value="Odeslat">';
    echo '</form>';
        }*/
    }
    ?>


</body>

</html>
