<?php
session_start();
include "db.php"; 
$conn = new mysqli($servername, $username, $password, $database);

if ($conn->connect_error) {
    die("Chyba připojení k databázi: " . $conn->connect_error);
}

// Odesílání mailu a tvoření session *****************************************************************************
if (isset($_POST["email"])) {
    $email = $_POST["email"];

    $query = "SELECT MAX(id) AS max_id FROM ticket";
    $result = $conn->query($query);
    $row = $result->fetch_assoc();
    $last_id = $row["max_id"];

    $new_id = $last_id + 1;

    $query = "SELECT id, state FROM ticket WHERE mail = '$email'";
    $result = $conn->query($query);

    $rec = []; 

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $id = $row["id"];
        $state = $row["state"];

        if ($state == "reserved" || $state == "paid") {
            $rec['state'] = "error";
        } else {
            $rec['state'] = "exist";
            $_SESSION["id"] = $id;
            $rec['id'] = $id;
        }
    } else {
        // Vytvoření nového záznamu s e-mailem a novým ID
        $insert_query = "INSERT INTO ticket (id, mail, state) VALUES ($new_id, '$email', 'free')";
        if ($conn->query($insert_query) === TRUE) {
            $rec['state'] = "new";
            $_SESSION["id"] = $new_id;
            $rec['id'] = $new_id;
        } else {
            $rec['state'] = "error";
            $rec['error_message'] = "Chyba při vytváření nového záznamu: " . $conn->error;
        }
    }

    // Vracíme odpověď ve formátu JSON
    echo json_encode($rec);
}

//checking checboxů*******************************************************************************

// AJAX požadavek pro získání informací o místech
if (isset($_POST["ids"])) {
    $ids = $_POST["ids"];
    include "db.php";
    $conn = new mysqli($servername, $username, $password, $database);

    if ($conn->connect_error) {
        die("Chyba připojení k databázi: " . $conn->connect_error);
    }

    $idList = implode(",", $ids);
    $sql = "SELECT id, state, ticket_id, reservedTime FROM place WHERE id IN ($idList)";
    $result = $conn->query($sql);

    $data = array();

    while ($row = $result->fetch_assoc()) {
        $id = $row["id"];
        $state = $row["state"];
        $ticketId = $row["ticket_id"];
        $times = $row['reservedTime'];

        $data[$id]['state'] = $state;
        $data[$id]['ticket_id'] = $ticketId;
        $data[$id]['times'] = $times;
        $data[$id]['timesA'] = time();
    }

    header('Content-Type: application/json');
    echo json_encode($data);
} 
// dočasná rezervace**************
else if (isset($_POST["idCh"]) && isset($_POST["emailR"])) {
    $idChair = $_POST["idCh"];
    $idUser = $_SESSION["id"];
    $emailR = $_POST["emailR"];
    $act = $_POST["act"];
    $ret = "";

    $conn = new mysqli($servername, $username, $password, $database);

    if ($conn->connect_error) {
        die("Chyba připojení k databázi: " . $conn->connect_error);
    }

    if (isset($_SESSION["id"])) {
        $sql = "SELECT * FROM ticket WHERE id = '$idUser' LIMIT 1";
        $result = $conn->query($sql);

        if ($result->num_rows == 1) {
            $sql = "SELECT state, ticket_id, reservedTime FROM place WHERE id = '$idChair'";
            $result = $conn->query($sql);
            $prvni = false;

            if (isset($_SESSION["time"])) {
                $prvni = false;
            } else {
                $_SESSION["time"] = time();
                $prvni = true;
            }

            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $state = $row["state"];
                $userI = $row["ticket_id"];
                $resT = $row["reservedTime"];

                if ($act == "true") {
                    if ($state == "free") {
                        $stateUpdate = "book";
                        $bookTime = $_SESSION["time"];
                        $sql = "UPDATE place SET state = '$stateUpdate', ticket_id = '$idUser', reservedTime = '$bookTime' WHERE id = '$idChair'";

                        if ($conn->query($sql) === TRUE) {
                            $ret = "save";
                        } else {
                            $ret = "error";
                        }
                    } else if ($state == "book" && (($currentTime - $resT) >= 120)) {
                        $stateUpdate = "book";
                        $bookTime = time();
                        $sql = "UPDATE place SET state = '$stateUpdate', ticket_id = '$idUser', reservedTime = '$bookTime' WHERE id = '$idChair'";

                        if ($conn->query($sql) === TRUE) {
                            $ret = "save";
                        } else {
                            $ret = "error";
                        }
                    }
                } else {
                    if ($userI == $idUser) {
                        $sql = "UPDATE place SET state = 'free', ticket_id = NULL, reservedTime = NULL WHERE id = '$idChair'";

                        if ($conn->query($sql) === TRUE) {
                            $ret = "save";
                        } else {
                            $ret = "error";
                        }
                    }
                }
            } else {
                echo "Záznam s ID $idChair nebyl nalezen v tabulce 'place'.";
            }
        } else {
            // Nepodařilo se
        }
    }

    header('Content-Type: application/json');
    echo json_encode($ret);
}



/*NUMERIC********************************************************************************************/
if (isset($_POST["numeri"])) {
    $numericik = $_POST["numeri"];
    //$value = $_POST["newVal"];
    $userId = $_SESSION["id"];
    if(isset($_SESSION["time"])){
        $prvni = false;
    }
    else{
        $_SESSION["time"] = time();
        $prvni = true;
    }
    include "db.php"; 

    $conn = new mysqli($servername, $username, $password, $database);

    $sqlSelectRecords = "SELECT * FROM place 
    WHERE stand = 1 AND state = 'book'";

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
                    echo "Záznam s ID $updateId úspěšně aktualizován<br>";
                } else {
                    echo "Chyba při aktualizaci záznamu s ID $updateId: " . $conn->error . "<br>";
                }
            }
        }
        $result->free_result();
    } else {
        echo "Chyba při provádění SELECT dotazu: " . $conn->error . "<br>";
    }

    if ($conn->connect_error) {
        die("Chyba připojení k databázi: " . $conn->connect_error);
    }
    if ($numericik == true) {
        $sqlSelectFreeRecord = "SELECT * FROM place WHERE stand = 1 AND state = 'free'";
        $resultSelectFreeRecord = $conn->query($sqlSelectFreeRecord);

        if ($resultSelectFreeRecord->num_rows > 0) {
            // Získání prvního volného záznamu
            $row = $resultSelectFreeRecord->fetch_assoc();

            // Získání ID prvního volného záznamu
            $freeRecordId = $row['id'];

            // Aktualizace záznamu na 'book' s odpovídajícími údaji
           
            $currentTime = time();
            $bookTime = $_SESSION["time"];
            $sqlUpdateRecord = "UPDATE place SET state = 'book', ticket_id = '$userId', reservedTime = '$bookTime' WHERE id = '$freeRecordId'";

            if ($conn->query($sqlUpdateRecord) === TRUE) {
                echo "success";
            } else {
                echo "Chyba při aktualizaci záznamu: " . $conn->error;
            }
        } else {
            echo "Nenalezen žádný volný záznam v tabulce place.";
        }
    } else {
        echo "neni ok";
    }
}


if (isset($_POST["numeric"])) {
    $numericik = $_POST["numeric"];
    $userId = $_SESSION["id"];

    include "db.php"; // Zahrnujte svůj soubor s připojením k databázi

    $conn = new mysqli($servername, $username, $password, $database);

    $sqlSelectRecords = "SELECT * FROM place 
    WHERE stand = 1 AND state = 'book'";

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
                    echo "Záznam s ID $updateId úspěšně aktualizován<br>";
                } else {
                    echo "Chyba při aktualizaci záznamu s ID $updateId: " . $conn->error . "<br>";
                }
            }
        }
        $result->free_result();
    } else {
        echo "Chyba při provádění SELECT dotazu: " . $conn->error . "<br>";
    }




    $sqlUpdateRecord = "UPDATE place 
                        SET state = 'free', ticket_id = NULL, reservedTime = NULL 
                        WHERE ticket_id = '$userId' AND stand = 1 AND state = 'book' LIMIT 1";

    if ($conn->query($sqlUpdateRecord) === TRUE) {
        echo "Záznam byl úspěšně aktualizován na 'book'.";
    } else {
        echo "Chyba při aktualizaci záznamu: " . $conn->error;
    }
}



if (isset($_POST["idCh2"]) && isset($_POST["emailR2"])) {
    $idChair = $_POST["idCh2"];
    $idUser = $_SESSION["id2"];
    $emailR = $_POST["emailR2"];
    $act = $_POST["act2"];
    $ret = ""; 
    $conn = new mysqli($servername, $username, $password, $database);
    if ($conn->connect_error) {
        die("Chyba připojení k databázi: " . $conn->connect_error);
    }
    if ($act == "true") {
        if (isset($_SESSION["id"])) {
            $sql = "SELECT * FROM ticket WHERE id = '$idUser' LIMIT 1";
            $result = $conn->query($sql);

            if ($result->num_rows == 1) {
                $sql = "SELECT state, ticket_id FROM place WHERE id = '$idChair'";
                $result = $conn->query($sql);

                if ($result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $state = $row["state"];
                    $userI = $row["ticket_id"];


                    if ($userI == $idUser) {
                        $sql = "UPDATE place SET state = 'free', ticket_id = NULL, reservedTime = NULL WHERE id = '$idChair'";

                        if ($conn->query($sql) === TRUE) {
                            $ret = "save";
                        } else {
                            $ret = "error";
                        }
                    }
                }
            } else {
                echo "Záznam s ID $idChair nebyl nalezen v tabulce 'place'.";
            }
        } 
    }


    echo json_encode($ret);
}

if (isset($_POST["maxTicket"])) {
    $conn = new mysqli($servername, $username, $password, $database);
    $sql = "SELECT maxTicket FROM settings";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $maxi = $result->fetch_assoc();
        $rit = $maxi["maxTicket"];
        echo json_encode($rit);
    } else {
        echo json_encode(['error' => 'Nenalezena žádná hodnota v databázi.']);
    }
    
    $conn->close();
}



if (isset($_POST["sesTime"])) {
    unset($_SESSION["time"]);
}


$conn->close();
