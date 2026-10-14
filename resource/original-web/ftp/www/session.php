<?php

session_start();

if(isset($_SESSION["id"])){
    echo $_SESSION["id"];
}
else{
    echo"ta mrdka nexistuje";
}
?>