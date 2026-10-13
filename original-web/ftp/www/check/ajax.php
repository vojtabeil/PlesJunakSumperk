<?php
include('../db.php');
session_start();

header('Content-Type: application/json');

$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die(json_encode(['status' => 'error', 'message' => 'Chyba připojení k databázi: ' . $conn->connect_error]));
}

if (!isset($_GET['check'])) {
    echo json_encode(['status' => 'error', 'message' => 'Chybí potřebný parametr "check".']);
    exit;
}

$mode = isset($_GET['mode']) ? $_GET['mode'] : 'check';
$checkValue = intval($_GET['check']);
$emailValue = "";
$emailProvided = false;
if (isset($_GET['email']) && trim($_GET['email']) !== "") {
    $emailValue = $conn->real_escape_string(trim($_GET['email']));
    $emailProvided = true;
}

// Získáme záznam z tabulky place podle předaného ID lístku (checkValue)
$sqlPlace = "SELECT ticket_id, usedTime FROM place WHERE id = $checkValue";
$result = $conn->query($sqlPlace);
if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $ticketIdPl = $row['ticket_id'];
    $usedTime = $row['usedTime'];
    
    // Pokud e‑mail nebyl předán, získáme ho z tabulky ticket podle ticket_id
    if (!$emailProvided) {
         $sqlTicket = "SELECT mail FROM ticket WHERE id = $ticketIdPl";
         $resultTicket = $conn->query($sqlTicket);
         if ($resultTicket && $resultTicket->num_rows > 0) {
             $rowTicket = $resultTicket->fetch_assoc();
             $emailValue = $rowTicket['mail'];
         } else {
             echo json_encode(['status' => 'error', 'message' => 'Záznam o lístku nebyl nalezen.']);
             exit;
         }
    } else {
         // Pokud byl e‑mail předán, ověříme, zda odpovídá lístku
         $sqlTicket = "SELECT id FROM ticket WHERE mail = '$emailValue'";
         $resultTicket = $conn->query($sqlTicket);
         if ($resultTicket && $resultTicket->num_rows > 0) {
             $rowTicket = $resultTicket->fetch_assoc();
             $ticketId = $rowTicket['id'];
             if ($ticketId != $ticketIdPl) {
                 echo json_encode(['status' => 'error', 'message' => 'Lístek je neplatný.']);
                 exit;
             }
         } else {
             echo json_encode(['status' => 'error', 'message' => 'Záznam s e‑mailem nebyl nalezen.']);
             exit;
         }
    }
    
    $additional = ['email' => $emailValue, 'ticketId' => $checkValue];
    
    if ($mode == 'check') {
        if ($usedTime === null) {
             echo json_encode(array_merge(['status' => 'valid', 'message' => 'Lístek je platný. Stiskněte tlačítko pro potvrzení.'], $additional));
        } else {
             echo json_encode(array_merge(['status' => 'used', 'message' => 'Lístek již byl použit dne: ' . date('Y-m-d H:i:s', $usedTime)], $additional));
        }
    } elseif ($mode == 'confirm') {
        if (!isset($_SESSION["admin"])) {
            echo json_encode(['status' => 'error', 'message' => 'Nejste přihlášen jako administrátor.']);
            exit;
        }
        if ($usedTime === null) {
            $currentUnixTime = time();
            $sqlUpdate = "UPDATE place SET usedTime = $currentUnixTime WHERE id = $checkValue";
            if ($conn->query($sqlUpdate) === TRUE) {
                echo json_encode(['status' => 'success', 'message' => 'Lístek byl úspěšně potvrzen.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Chyba při aktualizaci: ' . $conn->error]);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Lístek již byl použit.']);
        }
    } elseif ($mode == 'cancel') {
        if (!isset($_SESSION["admin"]) || strtolower($_SESSION["admin"]) !== 'pospa') {
            echo json_encode(['status' => 'error', 'message' => 'Nemáte oprávnění pro obnovení použití lístku.']);
            exit;
        }
        if ($usedTime !== null) {
            $sqlUpdate = "UPDATE place SET usedTime = NULL WHERE id = $checkValue";
            if ($conn->query($sqlUpdate) === TRUE) {
                echo json_encode(['status' => 'success', 'message' => 'Použití lístku bylo úspěšně obnoveno.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Chyba při aktualizaci: ' . $conn->error]);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Lístek ještě nebyl použit.']);
        }
    } else {
         echo json_encode(['status' => 'error', 'message' => 'Neznámý mód.']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Lístek nebyl nalezen.']);
}

$conn->close();
?>
