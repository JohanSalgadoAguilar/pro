<?php
$servername = "sql103.infinityfree.com";
$username   = "if0_43382934";
$password   = "cr0qu3tAs0o77";
$dbname     = "if0_43382932_proyecto"; // Cambia el sufijo _proyecto por el nombre exacto de tu BD en phpMyAdmin

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}
?>