<?php
// obtener_stock_json.php
header('Content-Type: application/json');

// Ajusta la inclusión de tu conexión a la base de datos según tu proyecto
require_once 'componentes/conexion.php'; // O el archivo donde tengas tu conexión PDO/mysqli

try {
    // Consultar nombre y stock de todos los productos
    $stmt = $conexion->prepare("SELECT nombre, stock FROM productos ORDER BY id ASC");
    $stmt->execute();
    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Formatear arreglos para Chart.js
    $labels = [];
    $data = [];

    foreach ($productos as $prod) {
        $labels[] = $prod['nombre'];
        $data[] = (int)$prod['stock'];
    }

    echo json_encode([
        'status' => 'success',
        'labels' => $labels,
        'data' => $data
    ]);
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>