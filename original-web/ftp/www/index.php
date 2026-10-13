<?php



session_start();



include "header.php";
include "db.php";
include "QRPlatba.php";
include "mailSet.php";


session_start();
$conn = new mysqli($servername, $username, $password, $database);

if ($conn->connect_error) {
    die("Připojení selhalo: " . $conn->connect_error);
}

$userIP = $_SERVER['REMOTE_ADDR'];

// Načtení povolených IP adres z databáze
$allowedIPs = [];
$sql = "SELECT adress FROM ip_adress";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $allowedIPs[] = $row['adress'];
    }
}
$operationSql = "SELECT operation, startTime FROM settings WHERE id = 1";
$operationResult = $conn->query($operationSql);
if ($operationResult && $operationResult->num_rows > 0) {
    $operationRow = $operationResult->fetch_assoc();
    $operation = $operationRow['operation'];
    $startTime = new DateTime($operationRow['startTime'], new DateTimeZone('Europe/Prague'));
} else {
    $operation = 0; 
}

$aktualniCas = new DateTime();

if ($operation == 1) {
    if ($aktualniCas > $startTime) {
        $operationStart = TRUE;
    } else {
        $operationStart = FALSE;
    }
} else {
    $operationStart = FALSE;
}

$userIP = $_SERVER['REMOTE_ADDR'];

if (in_array($userIP, $allowedIPs) || $operationStart) {
    if (in_array($userIP, $allowedIPs) && $operationStart == FALSE) {
        echo "<div class='cointainer'>";
        echo "<div class='adminInfo' style='width: 100%;'>Jste přihlášen jako administrátor.</div>";
        echo "</div>";
    }
    $conn = new mysqli($servername, $username, $password, $database);

    if ($conn->connect_error) {
        die("Chyba připojení k databázi: " . $conn->connect_error);
    }
    /*odesílání **************************************************************************************/
    if (isset($_POST["emailInput"]) && isset($_POST["jmenoInput"]) && !empty($_POST["emailInput"]) && !empty($_POST["jmenoInput"]) && isset($_POST["phoneInput"]) && !empty($_POST["phoneInput"])) {

        $jmeno = $_POST["jmenoInput"];
        $telefon = $_POST["phoneInput"];
        if (isset($_SESSION['id'])) {
            $ticketId = $_SESSION["id"];
        } else {
            echo "Session 'id' není nastavena.";
        }
        $times = time();
        $over = true;
        $mailik = $_POST["emailInput"];
        $maisql = "SELECT state FROM ticket WHERE mail ='$mailik'";
        $mailRes = $conn->query($maisql);
        if ($mailRes) {
            $row = $mailRes->fetch_assoc();
            if (isset($row['state']) && $row['state'] == "free" || $row['state'] == "storno") {
                if (isset($_POST['places']) || isset($_POST["stand"])) {
                    if (isset($_POST['places'])) {
                        $places = $_POST['places'];
                    }
                    $pricingSql = "SELECT sitPrice, standPrice, bankNumber, datePles, sender_mail, maxTicket FROM settings";
                    $pricingResult = $conn->query($pricingSql);
                    if ($pricingResult) {
                        $row = $pricingResult->fetch_assoc();
                        $sitPrice = $row['sitPrice'];
                        $standPrice = $row['standPrice'];
                        $bankNumber = $row['bankNumber'];
                        $datePles = $row['datePles'];
                        $sender = $row['sender_mail'];
                        $maxTicket = $row['maxTicket'];
                        $price = 0;
                        $count = 0;
                        if (isset($_POST['places'])) {

                            foreach ($places as $place_id) {
                                $safePlaceId = $conn->real_escape_string($place_id);

                                $checkSql = "SELECT state, ticket_id, reservedTime FROM place WHERE id = '$safePlaceId' AND stand = 0";
                                $checkResult = $conn->query($checkSql);

                                if ($checkResult) {
                                    $row = $checkResult->fetch_assoc();
                                    $stav = $row['state'];
                                    $tiketik = $row['ticket_id'];
                                    $timesik = $row['reservedTime'];


                                    if ($stav == 'book' && $tiketik == $ticketId && (time() - $timesik) <= 120) {
                                        // Aktualizace záznamu v tabulce place
                                        if ($over == true) {
                                            $sql = "UPDATE place SET state = 'reserved', reservedTime = '$times' WHERE id = '$safePlaceId'";

                                            if ($conn->query($sql) !== TRUE) {
                                                echo "Chyba při aktualizaci: " . $sql . "<br>" . $conn->error;
                                                $over = false;
                                            } else {
                                                $price += $sitPrice;
                                                $count++;
                                                $over = true;
                                            }
                                        } else {
                                            $sql = "UPDATE place SET ticket_id = NULL, state = 'free', reservedTime = NULL WHERE ticket_id = $ticketId";

                                            if ($conn->query($sql) === TRUE) {
                                                echo "Záznamy byly úspěšně aktualizovány.";
                                            } else {
                                                echo "Chyba při aktualizaci záznamů: " . $conn->error;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                        $sql = "SELECT * FROM place 
                WHERE ticket_id = '$ticketId' AND stand = 1 AND state = 'book'";
                        $result = $conn->query($sql);

                        if ($result) {
                            while ($row = $result->fetch_assoc()) {
                                $reservedTime = $row['reservedTime'];
                                $currentTime = time();
                                $id = $row['id'];
                                $tiketik = $row['ticket_id'];
                                $stav = $row['state'];
                                if ($over == true) {
                                    if ($stav == 'book' && $tiketik == $ticketId && (time() - $reservedTime) <= 120) {
                                        $sql = "UPDATE place SET state = 'reserved', reservedTime = '$times' WHERE id = '$id'";

                                        if ($conn->query($sql) !== TRUE) {
                                            echo "Chyba při aktualizaci: " . $sql . "<br>" . $conn->error;
                                            $over = false;
                                        } else {
                                            $price += $standPrice;
                                            $over = true;
                                            $count++;
                                        }
                                    }
                                } else {
                                    $sql = "UPDATE place SET ticket_id = NULL, state = 'free', reservedTime = NULL WHERE ticket_id = $ticketId";

                                    if ($conn->query($sql) === TRUE) {
                                        echo "Záznamy byly úspěšně aktualizovány.";
                                    } else {
                                        echo "Chyba při aktualizaci záznamů: " . $conn->error;
                                    }
                                }
                            }
                        }
                        if ($over == true && $count <= $maxTicket) {
                            $sqlT = "UPDATE ticket SET state = 'reserved', price = '$price', name = '$jmeno', phone = '$telefon' WHERE id = '$ticketId'";
                            if ($conn->query($sqlT) !== TRUE) {
                                echo "Chyba při aktualizaci: " . $sqlT . "<br>" . $conn->error;
                            } else {

                                $platba = new QRPlatba($bankNumber, $price);
                                $platba->setMessage("Platba za lítky na ples");
                                $platba->setVariableSym($ticketId);
                                function getQrCode(string $url, int $size = 180, string $charset = 'UTF-8'): string
                                {
                                    $size = $size < 8 ? 8 : ($size > 2048 ? 2048 : $size);

                                    return /*'<img src="https://chart.apis.google.com/chart?cht=qr&chs='
                                        . $size . 'x' . $size
                                        . '&choe=' . urlencode($charset)
                                        . '&chld=H%7C0&chl=' . urlencode($url)
                                        . '">';*/
                                        '<img src="https://api.qrserver.com/v1/create-qr-code/?size='
                                        . $size . 'x' . $size
                                        . '&data='
                                        .  urlencode($url)
                                        . '" alt="qr-code">';
                                }

                                $odeslat = getQrCode($platba);
                                $sqlTotal = "SELECT COUNT(*) as total FROM place WHERE state = 'reserved' AND ticket_id = '$ticketId'";
                                $resultTotal = $conn->query($sqlTotal);
                                $rowTotal = $resultTotal->fetch_assoc();
                                $totalPlaces = $rowTotal['total'];
                                echo '<div class="cointainer">
                                <div class="spl">
                                     <div class="menu">
                                        <h1 class="trasnparent">šumperský</h1>
                                        <h1>SKAUTSKÝ PLES</h1>
                            </div>
                            <div class="nadpisos2">';


                                echo "<ul>
                        
                        <li>Rezervováno na jméno: " . $jmeno . "</li>
                        <li>Počet lístků: " . $totalPlaces . "</li>
                        <li>Celková cena: <strong>" . $price . "</strong> Kč</li>
                        <li>Variabilní symbol: <strong>" . $ticketId . "</strong></li>
                        <li>Číslo účtu pro platbu: <strong>" . $bankNumber . "</strong> (Fio Banka)</li>
                        <li>Splatnost: <strong>" . (new DateTime('+1 week'))->format('d. m. Y') . "</strong></li>
                        <li>Datum plesu: " . $datePles . "</li>
                    </ul>
                    <p>Pro zrychlení platby jsme připravili QR kód, který lze použít při placení v mobilních aplikacích bank.</p>
                    <p>" . /*getQrCode($platba)*/ $odeslat . "</p>";
                                echo '   </div>
                                <
                        </div>
                        ';
                                /*mail****************************************************************************************/
                                $mailHeaders = 'Content-Type: text/html; charset=utf-8' . "\r\n" . "From: Skautský ples Šumperk <" . $sender . ">";
                                $message = "
                        <p>Dobrý den,<br>přijali jsme Vaši rezervaci lístk" . (($count > 1) ? "ů" : "u") . " na ples. <strong>Je potřeba je zaplatit do " . (new DateTime('+1 week'))->format('d. m. Y') . "!</strong> Jinak rezervace propadne.</p>
                        <ul>
                            <li>Rezervováno na jméno: " . $jmeno . "</li>
                            <li>Počet lístků: " . $totalPlaces . "</li>
                            <li>Celková cena: <strong>" . $price . "</strong> Kč</li>
                            <li>Variabilní symbol: <strong>" . $ticketId . "</strong></li>
                            <li>Číslo účtu pro platbu: <strong>" . $bankNumber . "</strong> (Fio Banka)</li>
                            <li>Splatnost: <strong>" . (new DateTime('+1 week'))->format('d. m. Y') . "</strong></li>
                            <li>Datum plesu: " . $datePles . "</li>
                        </ul>
                        <p>Jakmile přijmeme a identifikujeme vaši platbu, pošleme Vám lístky elektronicky. Lístky si poté sami vytisknete a donesete v den plesu s sebou.</p>
                        
                        <p>Těšíme se na Vás!<br>Lukáš Rozsíval</p> 
                        
                        <br>
                        <br>
                        <p>Pro zrychlení platby jsme připravili QR kód, který lze použít při placení v mobilních aplikacích bank. Použití QR kódu šetří váš čas a snižuje pravděpodobnost překlepu.</p>
                        <p>" .
                                    //getQrCode($platba) 
                                    $odeslat
                                    . "</p>
                        
                        <p>Junák - český skaut<br>
                        Středisko Rudy Knotka Šumperk, z. s.<br>
                        Nám. Jana Zajíce 11<br>
                        787 01 Šumperk</p>";
                                $subject = "Rezervace lístk" . (($count > 1) ? "ů" : "u") . " na skautský ples byla přijata";
                                $to = $mailik;
                                sendEmail($to, $subject, $message);
                            }
                            //odesílání mailu
                            //echo "Rezervace se zdařil"
                        } else {
                            //echo "databaze v riti";

                            $sql = "UPDATE place SET ticket_id = NULL, state = 'free', reservedTime = NULL WHERE ticket_id = $ticketId";

                            if ($conn->query($sql) === TRUE) {
                                echo "Záznamy byly úspěšně aktualizovány.";
                            } else {
                                echo "Chyba při aktualizaci záznamů: " . $conn->error;
                            }

                            // Uzavření spojení s databází
                            $conn->close();
                        }
                    }
                } else {
                    echo "Zadejte všechny údaje";
                }
            } else {
                header("location: index.php");
                exit();
            }
        }
    } else { //formulář neodeslán a bude se vytváře hlavní page ***************************************************************
        /**počítání celkové obsazenosti a kolik je míst *************************************************************************** */
        // Celkový počet záznamů
        $_SESSION = array(); 

        session_destroy();

        session_start();
        session_regenerate_id(true);
        $conn = new mysqli($servername, $username, $password, $database);
        $sql = "SELECT maxTicket FROM settings LIMIT 1";

        $result = $conn->query($sql);

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $maxTicket = $row["maxTicket"];

        } 
        $sqlSelectRecords = "SELECT * FROM place 
    WHERE state = 'book'";

        $result = $conn->query($sqlSelectRecords);

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $reservedTime = $row['reservedTime'];
                $currentTime = time();
                $updateId = $row['id'];
                if (($currentTime - $reservedTime) >= 120) {  // Starší než dvě minuty


                    $sqlUpdateRecord = "UPDATE place 
                SET ticket_id = NULL, reservedTime = NULL, state = 'free' 
                WHERE id='$updateId'";

                    if ($conn->query($sqlUpdateRecord) === TRUE) {
                    } else {
                        echo "Chyba při aktualizaci záznamu s ID $updateId: " . $conn->error . "<br>";
                    }
                }
            }
            $result->free_result();
        } else {
            echo "Chyba při provádění SELECT dotazu: " . $conn->error . "<br>";
        }

        $sqlTotal = "SELECT COUNT(*) as total FROM place WHERE stand = 0 AND state = 'free'";
        $resultTotal = $conn->query($sqlTotal);
        $rowTotal = $resultTotal->fetch_assoc();
        $totalPlaces = $rowTotal['total'];

        $standFreeTotal = "SELECT COUNT(*) AS stand_1_free_total FROM place WHERE stand = 1 AND state = 'free'";
        $resultStandFreeTotal = $conn->query($standFreeTotal);
        $rowStandFreeTotal = $resultStandFreeTotal->fetch_assoc();
        $totalStandFreePlaces = $rowStandFreeTotal['stand_1_free_total'];

        $sql = "SELECT sitPrice, standPrice FROM settings";
        $result = $conn->query($sql);

        // Zpracování výsledků
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc(); // Získání prvního a jediného řádku

            $sitPrice = $row['sitPrice'];
            $standPrice = $row['standPrice'];
        }
        echo '<div class="cointainer">
                <div class="spl">
                    <div class="menu">
                        <h1 class="trasnparent">šumperský</h1>
                        <h1>SKAUTSKÝ PLES</h1>
                    </div>
                    <div class="nadpisos2">
                        <div class="uvodik">
                            <p class="uvod">Šumperští skauti si vás dovolují pozvat do víru tance a zábavy. Letos již 15. ročníkem
                                plesu šumperských skautů se protančíme za doprovodu skupiny Lucky Band. Chybět nebude ani
                                tradičně vynikající občerstvení, klasická i skautská tombola a bohatý program. Na plese se již od letošního ročníku, bude moct dát platit kartou. Přijďte se pobavit,
                                setkat s přáteli a strávit příjemný večer v pohodové atmosféře!</p>
                                <p class="ikonT" title="Kde" ><img src="ikony/kde.svg" class="ikon" title="Kde" alt="kde"><a href="https://maps.app.goo.gl/aJU2PTvT3JSvg51V9" target="_blank">Sokolovna Nový Malín</a></p>
                                <p class="ikonT" title="Kdy"><img src="ikony/kdy.svg" class="ikon" title="Kdy" alt="kdy">7. 2. 2026 od 19:00</p>
                                <p class="ikonT" title="Kapela"><img src="ikony/kapela.svg" class="ikon" title="Kapela" alt="kdy"><a href="https://www.luckyband.cz/" target="_blank">Lucky Band</a></p>
                                <p class="ikonT" title="Organizátor"><img src="ikony/organizator.svg" class="ikon" title="Organizátor" alt="organizator">Lukáš Rozsíval</p>
                            </div> 
                    
                    </div>
                </div>
';
        echo '<h2 class="h2R">REZERVACE LÍSTKŮ</h2>';
        echo '<form action="index.php" method="POST">
        <div>
        <div class="infTable-container">
            <table class="infTable">
                <tr>
                    <td  colspan="4" class="textColumn"><span style="color: red; font-size: 1.5rem;">Prodej lístků končí 5. 2. 2026. Poté bude možné zakoupit zbývající lístky na místě.</span>
                    <br>Postup při rezervaci lístků:<br>
                    Nejprve zadejte mail, kam Vám budou lístky zadány. Následně budete moci vybírat místa a spustí se <strong> dočasná 2 minutová rezervace </strong>, kterou uvidíte na odpočtu. Pokud rezrvaci nedodešlete do vypršení časového limitu Vaše rezrvace zmizí a stránka se obnoví.<br>
                    Na každý mail je povolená pouze jedna rezervace (max. ' . $maxTicket . ' lístků).<br><br>
                    V případě problémů volejte Janu Pospíšilovi na telefonní číslo <strong> 731 493 190</strong>  nebo piště na e-mail: <strong> ples@junak-sumperk.cz.</strong> 
                <tr style="margin-bottom: 25px;">
                    <td colspan="2" style="padding-bottom: 25px;"><strong>Cena lístku</strong>
                    <td colspan="2" style="padding-bottom: 25px;"><strong>Počet volných lístků</strong>
                    </tr>
                <tr>
                    <td><img src="ikony/sezeni_cena.svg" class="ikonTa" alt="cenasezeni">
                    <td>Na sezení:<strong> ' . $sitPrice . 'Kč</strong>
                    <td><img src="ikony/sezeni_volnych.svg" class="ikonTa" alt="volnysezeni">
                    <td>Počet volných<br>lístků na sezení v <br><strong>' . $totalPlaces  /*(($totalPlaces - 133) < 0 ? "0" : ($totalPlaces - 133))*/ . '</strong>
                <tr>
                    <td><img src="ikony/stani_cena.svg" class="ikonTa" alt="cenasezeni">
                    <td>Na stání: <strong> ' . $standPrice . 'Kč</strong> 
                    <td><img src="ikony/stani_volnych.svg" class="ikonTa" alt="volnysezeni">
                    <td>Počet volných<br>lístků na stání v <br><strong>' . $totalStandFreePlaces /*(($totalStandFreePlaces - 20) < 0 ? "0" : ($totalStandFreePlaces - 20))*/ . '</strong>
            </table>
        </div>
        <div class="infTable-container">
            <table class="infTable2">
                <tr>
                    <td><img src="ikony/jmeno_prijmeni.svg" class="ikonTa" alt="jmenoPrijmeni"  title="Zadejte jméno a příjmení" >
                    <td><input type="text" name="jmenoInput" id="jmenoInput" placeholder="Zadejte jméno a příjmení" required pattern="^[a-zA-ZáÁčČďĎéÉěĚíÍňŇóÓřŘšŠťŤúÚůŮžŽýÝ\- ]{2,20}$" title="Jméno musí obsahovat 2 až 20 znaků" style="width:65%">
                <tr>
                    <td>
                    <td><span id="overeni"></span>
                <tr>
                    <td><img src="ikony/mail.svg" class="ikonTa" alt="mail"  title="Zadejte mail">
                    <td><input type="email" name="emailInput" id="emailInput" placeholder="Zadejte e-mail" pattern="^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$" required style="width:65%">
                <tr>
                    <td><img src="ikony/telefon.svg" class="ikonTa" alt="telefon" title="Zadejte telefon">
                    <td><input type="tel" id="phoneInput" name="phoneInput" placeholder="Zadejte telefonní číslo" pattern="^\+?\d{0,4}?\s?-?\d{3,}?-?\d{2,}?-?\d{2,}?$" required style="width:65%">
                <tr>
                    <td><img src="ikony/karta.svg" class="ikonTa" alt="mail">
                    <td>Platba pouze převodem
                <tr>
                    <td><img src="ikony/pocet_listku.svg" class="ikonTa" alt="mail">
                    <td>Můžete rezervovat<br> maximálně <strong>' . $maxTicket . '</strong> lístků
            </table>
        </div>
        </div>
        <br>';
        echo '<div class="incon">';


        echo '<p>Odpočet:</p>
            <p id="odpocet">Zbývající čas rezervace: 120 sekund</p>';
        echo "<p>Lístky na stání</p>";
        if (($totalStandFreePlaces > 0)) {
            echo '<div class="quantity">
        <input type="number" min="0" max="' . $totalStandFreePlaces . '"  value="0" name="stand" id="numeric"><p id="nene"></p>
        </div>';
        } else {
            echo "<h3 style='color: red;'>Lístky na stání byly vyprodány</h3><br>";
        }
        echo '<div><p><span class="chair"></span>&nbsp;&nbsp;&nbsp;&nbsp; - volné místo</p><p><span class="chair-selected"></span>&nbsp;&nbsp;&nbsp;&nbsp; - vybranné místo </p><p><span class="chair-reserved"></span>&nbsp;&nbsp;&nbsp;&nbsp; - obsazené místo</p></div><br><br>
        </div>';
        if ($totalPlaces > 0) {
            // Dotaz pro načtení dat z tabulky "place"
            $sql = "SELECT * FROM place WHERE stand = 0";
            $result = $conn->query($sql);
            // Kontrola, zda byly data načteny
            if ($result->num_rows > 0) {
                // Vytvoření divu pro checkboxy

                echo '<div class="flex-container">
                <div class="map-viewport">
            <div id="checkbox-container">';

                // Procházení dat z databáze a vytváření checkboxů s pozicemi x a y
                while ($row = $result->fetch_assoc()) {
                    $x = $row['x']; // Hodnota x
                    $y = $row['y']; // Hodnota y
                    $id = $row['id']; // Název místa 
                    $state = $row['state']; //stav místa
                    $reservedTime = $row['reservedTime'];
                    
                    if ($state == "reserved") {
                        echo "<span style='left: " . $x . "%; top: " . $y . "%' class='chair-reserved' id='span" . $id . "'>";
                        echo '<input type="checkbox" onclick="changeClass(' . $id . ')" id="' . $id . '" name="places[]" value="' . $id . '" class="' . $state . '" data-id="' . $id . '" disabled>';
                        echo $id;
                        echo '</span>';
                    }
                    if ($state == "book" && (time() - $reservedTime) <= 120) {
                        echo "<span style='left: " . $x . "%; top: " . $y . "%' class='chair-reserved' id='span" . $id . "'>";
                        echo '<input type="checkbox" onclick="changeClass(' . $id . ')" id="' . $id . '" name="places[]" value="' . $id . '" class="' . $state . '" data-id="' . $id . '">';
                        echo $id;
                        echo '</span>';
                    }
                    if ($state == "book" && (time() - $reservedTime) >= 120) {
                        echo "<span style='left: " . $x . "%; top: " . $y . "%' class='chair' id='span" . $id . "'>";
                        echo '<input type="checkbox" onclick="changeClass(' . $id . ')" id="' . $id . '" name="places[]" value="' . $id . '" class="free" data-id="' . $id . '">';
                        echo $id;
                        echo '</span>';
                    }
                    if ($state == "free") {
                        echo "<span style='left: " . $x . "%; top: " . $y . "%' class='chair' id='span" . $id . "'>";
                        echo '<input type="checkbox" onclick="changeClass(' . $id . ')" id="' . $id . '" name="places[]" value="' . $id . '" class="' . $state . '" data-id="' . $id . '">';
                        echo $id;
                        echo '</span>';
                    }
                }
                echo "<img src='planek2025.jpg' style='max-width: 100%;' alt='hala' class='map-image'>";
                echo '</div></div></div><br>';
            } else {
                echo "Žádná data nebyla nalezena v databázi.";
            }
        } else {
            echo "<h3 style='color: red;'>Lístky na sezení vyprodány.</h3><br>";
        }
        echo '<button id="odeslat">Odeslat</button><br>';
        echo '</form>';
        $conn = new mysqli($servername, $username, $password, $database);
        $sql = "SELECT maxTicket FROM settings LIMIT 1";

        $result = $conn->query($sql);

        // Kontrola, zda dotaz vrátil výsledek
        if ($result->num_rows > 0) {
            // Přečtení hodnoty
            $row = $result->fetch_assoc();
            $maxTicket = $row["maxTicket"];

            // Zde můžete dále pracovat s hodnotou $maxTicketValue
            //echo "Hodnota maxTicket z databáze: " . $maxTicket;
        } else {
            //echo "Žádné výsledky nalezeny";
        }
        // Uzavření připojení k databázi
        $conn->close();
    }
} else {
    // Display text for users with different IP addresses
    echo '<div class="cointainer">
    <div class="spl">
        <div class="menu">
        <h1 class="trasnparent">šumperský</h1>
        <h1>SKAUTSKÝ PLES</h1>
    </div>
    <div class="nadpisos2">
       <h3>Děkujeme za Vaši účast na našem plese. Fotografie z roku 2026 naleznete <a href="https://eu.zonerama.com/fotografvaclavhampl/Album/14769688">zde</a></h3>
       
    </div>
    </div>
';
}
$conn = new mysqli($servername, $username, $password, $database);
$sql = "SELECT maxTicket FROM settings LIMIT 1";

$result = $conn->query($sql);



?>


<script>
    var maxTicket = <?php echo $maxTicket ?>;
    
</script>
<script src="script.js?v=<?= filemtime('script.js') ?>"></script>

<?php
include "footer.php";
?>