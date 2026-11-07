<?php
session_start();
include 'componentes/conexion.php';

// Verificar que sea administrador
if (!isset($_SESSION['usuario']) || $_SESSION['rol'] !== 'admin') {
    header("Location: index.php");
    exit();
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nombre = trim($_POST['nombre']);
    $stock = trim($_POST['stock']);
    $precio = trim($_POST['precio']);

    // Verificar si se ha seleccionado una imagen correctamente
    if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
        // Validar que realmente sea una imagen
        $mime = class_exists('finfo') ? (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['imagen']['tmp_name']) : 'image/jpeg';

        if (strpos($mime, 'image/') !== 0) {
            $error = "El archivo seleccionado no es una imagen válida.";
        } elseif ($nombre !== '' && is_numeric($stock) && $stock >= 0 && is_numeric($precio) && $precio >= 0) {
            // Leer los datos binarios del archivo temporal
            $imagenData = file_get_contents($_FILES['imagen']['tmp_name']);

            try {
                // Insertar incluyendo el BLOB de la imagen y el precio
                $stmt = $conexion->prepare("INSERT INTO productos (nombre, stock, precio, imagen, fecha_creacion) VALUES (:nombre, :stock, :precio, :imagen, NOW())");
                
                $stmt->bindParam(':nombre', $nombre);
                $stmt->bindParam(':stock', $stock);
                $stmt->bindParam(':precio', $precio);
                $stmt->bindParam(':imagen', $imagenData, PDO::PARAM_LOB);

                if ($stmt->execute()) {
                    header("Location: productos.php");
                    exit();
                } else {
                    $error = "Error al registrar el producto.";
                }
            } catch (PDOException $e) {
                $error = "Error en la base de datos. Inténtalo de nuevo.";
            }
        } else {
            $error = "Por favor, completa todos los campos con valores válidos.";
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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TechPCs | Nuevo producto</title>
    <link rel="stylesheet" href="componentes/estilos.css?v=1">
</head>
<body>

<nav class="navbar">
    <a href="productos.php" class="marca">Tech<span>PCs</span></a>
    <div class="nav-der">
        <span class="rol">Admin: <?php echo htmlspecialchars($_SESSION['usuario']); ?></span>
        <a href="productos.php" class="btn btn-gris">← Volver al inventario</a>
    </div>
</nav>

<div class="container container-sm">
    <div class="panel">
        <div class="panel-top">
            <div>
                <h1>Añadir nuevo producto</h1>
                <p>Completa los datos para agregarlo al inventario</p>
            </div>
        </div>

        <div class="panel-body">
            <?php if (!empty($error)): ?>
                <div class="alerta alerta-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <!-- enctype="multipart/form-data" es necesario para subir la imagen -->
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="nombre">Nombre del producto</label>
                    <input type="text" id="nombre" name="nombre" required>
                </div>

                <div class="form-group">
                    <label for="stock">Stock</label>
                    <input type="number" id="stock" name="stock" min="0" required>
                </div>

                <div class="form-group">
                    <label for="precio">Precio sin IVA ($)</label>
                    <input type="number" id="precio" step="0.01" min="0" name="precio" placeholder="Ej. 150.00" required>
                </div>

                <div class="form-group">
                    <label for="imagen">Imagen</label>
                    <input type="file" id="imagen" name="imagen" accept="image/*" required>
                </div>

                <button type="submit" class="btn btn-verde btn-block">Guardar producto</button>
            </form>
        </div>
    </div>
</div>

</body>
</html>