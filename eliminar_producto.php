<?php
session_start();
include 'componentes/conexion.php';

if (!isset($_SESSION['usuario']) || $_SESSION['rol'] !== 'admin') {
    header("Location: index.php");
    exit();
}

if (isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $conexion->prepare("DELETE FROM productos WHERE id = :id");
    $stmt->execute([':id' => $id]);
}

header("Location: productos.php");
exit();
?>