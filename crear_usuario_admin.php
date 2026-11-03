<?php
session_start();
include 'componentes/conexion.php';

if (!isset($_SESSION['usuario']) || $_SESSION['rol'] !== 'admin') {
    header("Location: index.php");
    exit();
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nombre = trim($_POST['nombre']);
    $stock = trim($_POST['stock']);

    // Verificar si se ha enviado una imagen sin errores
    if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
        // Leer los datos binarios del archivo temporal
        $imagenData = file_get_contents($_FILES['imagen']['tmp_name']);

        if (!empty($nombre) && !empty($stock)) {
            try {
                // Insertar los datos incluyendo el BLOB
                $stmt = $conexion->prepare("INSERT INTO productos (nombre, stock, imagen, fecha_creacion) VALUES (:nombre, :stock, :imagen, NOW())");
                
                $stmt->bindParam(':nombre', $nombre);
                $stmt->bindParam(':stock', $stock);
                $stmt->bindParam(':imagen', $imagenData, PDO::PARAM_LOB); // Vinculación como LOB

                if ($stmt->execute()) {
                    header("Location: productos.php");
                    exit();
                } else {
                    $error = "Error al registrar el producto.";
                }
            } catch (PDOException $e) {
                $error = "Error en la base de datos: " . $e->getMessage();
            }
        } else {
            $error = "Por favor, completa los campos obligatorios.";
        }
    } else {
        $error = "Es obligatorio seleccionar una imagen válida.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Añadir Nuevo Producto</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f9; padding: 40px; display: flex; justify-content: center; align-items: center; height: 90vh; margin: 0; }
        .box { background: white; padding: 25px; border-radius: 8px; width: 350px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        h3 { margin-top: 0; color: #333; text-align: center; }
        label { display: block; margin-bottom: 5px; color: #555; font-size: 14px; }
        input { width: 100%; padding: 8px; margin-bottom: 12px; box-sizing: border-box; border: 1px solid #ccc; border-radius: 4px; }
        button { width: 100%; padding: 10px; background: #28a745; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 15px; }
        button:hover { background: #218838; }
        .error { color: red; font-size: 14px; margin-bottom: 10px; text-align: center; }
        .volver { display: block; text-align: center; margin-top: 15px; color: #007bff; text-decoration: none; }
        .volver:hover { text-decoration: underline; }
    </style>
</head>
<body>

<div class="box">
    <h3>Añadir Nuevo Producto</h3>
    
    <?php if (!empty($error)) { echo "<div class='error'>$error</div>"; } ?>

    <!-- Importante: enctype="multipart/form-data" para enviar archivos -->
    <form method="POST" enctype="multipart/form-data">
        <label>Producto:</label>
        <input type="text" name="nombre" required>

        <label>Stock:</label>
        <input type="number" name="stock" required>

        <label>Imagen:</label>
        <input type="file" name="imagen" accept="image/*" required>

        <button type="submit">Guardar Producto</button>
    </form>
    
    <a href="productos.php" class="volver">Volver al catálogo</a>
</div>

</body>
</html>