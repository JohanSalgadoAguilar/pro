<?php
// Credenciales centralizadas para InfinityFree (Producción) y local
$servername = "sql301.byetcluster.com"; // Tu servidor MySQL de InfinityFree
$username   = "if0_43348523";
$password   = "cr0qu3tAs0o7";
$dbname     = "if0_43348523_proyecto";

try {
    $conexion = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8mb4", $username, $password);
    // Configurar el modo error de PDO a excepción
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Error de conexión a la base de datos: " . $e->getMessage());
}
?>