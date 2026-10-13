<?php

require 'db.php';

$conn = new mysqli($servername, $username, $password, $database);

if ($conn->connect_error) {
    die("Připojení selhalo: " . $conn->connect_error);
}

$ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];

$sqlCheck = "SELECT COUNT(*) FROM ip_adress WHERE adress = ?";
$stmtCheck = $conn->prepare($sqlCheck);
$stmtCheck->bind_param('s', $ipAddress);
$stmtCheck->execute();
$stmtCheck->bind_result($count);
$stmtCheck->fetch();
$stmtCheck->close();

if ($count == 0) {

    $result = $conn->query("SELECT MAX(id) AS max_id FROM ip_adress");
    $row = $result->fetch_assoc();
    $nextId = $row['max_id'] + 1; 
    $result->free();

    $sqlInsert = "INSERT INTO ip_adress (id, adress) VALUES (?, ?)";
    $stmtInsert = $conn->prepare($sqlInsert);

    if ($stmtInsert) {
        $stmtInsert->bind_param('is', $nextId, $ipAddress);
        $stmtInsert->execute();
        $stmtInsert->close();
    } else {
        error_log("Chyba při přípravě dotazu: " . $conn->error);
    }
}

$conn->close();

header("Location: index.php");
exit();
    
?>
