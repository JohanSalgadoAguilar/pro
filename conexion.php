<?php
$servername = "sql301.byetcluster.com";
$username = "if0_43348523";
$password = "cr0qu3tAs0o7";
$dbname = "if0_43348523_proyecto";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}
?>