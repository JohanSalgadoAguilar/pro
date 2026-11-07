<?php
$servername = "sql103.infinityfree.com";
$username   = "if0_43382934";
$password   = "cr0qu3tAs0o77"; // Contraseña con 2 sietes al final
$dbname     = "if0_43382934_proyecto"; 

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Error de conexión a la base de datos: " . $e->getMessage());
}
?>