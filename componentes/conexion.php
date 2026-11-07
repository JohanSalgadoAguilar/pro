<?php
// Conexión centralizada PDO para la cuenta nueva
$servername = "sql301.byetcluster.com"; // Verifica este hostname en MySQL Details de tu panel
$username   = "if0_43382934";
$password   = "P41YcHIsHOIDM";
$dbname     = "if0_43382934_proyecto"; // Cambia _proyecto si tu base de datos tiene otro sufijo

try {
    $conexion = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8mb4", $username, $password);
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Error de conexión a la base de datos: " . $e->getMessage());
}
?>