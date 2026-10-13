<?php
session_start();
session_destroy();
header("Location: tets.php");
exit;
?>
