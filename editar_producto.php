<?php
session_start();
include 'componentes/conexion.php';

if (!isset($_SESSION['usuario']) || $_SESSION['rol'] !== 'admin') {
    header("Location: index.php");
    exit();
}

$error = "";
$producto = ['id' => '', 'nombre' => '', 'stock' => '', 'precio' => '0.00', 'fecha_creacion' => ''];

if (isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $conexion->prepare("SELECT id, nombre, stock, precio, fecha_creacion FROM productos WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $producto = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$producto) {
        header("Location: productos.php");
        exit();
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $id = $_POST['id'];
    $nombre = trim($_POST['nombre']);
    $stock = trim($_POST['stock']);
    $precio = trim($_POST['precio']);
    $fecha_creacion = trim($_POST['fecha_creacion']);

    if (!empty($nombre) && !empty($stock) && isset($precio)) {
        try {
            if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
                $imagenData = file_get_contents($_FILES['imagen']['tmp_name']);

                $stmt = $conexion->prepare("UPDATE productos SET nombre = :n, stock = :s, precio = :p, imagen = :i, fecha_creacion = :f WHERE id = :id");
                $resultado = $stmt->execute([
                    ':n' => $nombre,
                    ':s' => $stock,
                    ':p' => $precio,
                    ':i' => $imagenData,
                    ':f' => $fecha_creacion,
                    ':id' => $id
                ]);
            } else {
                $stmt = $conexion->prepare("UPDATE productos SET nombre = :n, stock = :s, precio = :p, fecha_creacion = :f WHERE id = :id");
                $resultado = $stmt->execute([
                    ':n' => $nombre,
                    ':s' => $stock,
                    ':p' => $precio,
                    ':f' => $fecha_creacion,
                    ':id' => $id
                ]);
            }

            if ($resultado) {
                header("Location: productos.php");
                exit();
            } else {
                $error = "Error al actualizar el producto.";
            }
        } catch (PDOException $e) {
            $error = "Error: " . $e->getMessage();
        }
    } else {
        $error = "Por favor, completa los campos obligatorios.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Actualizar Producto</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f9; padding: 40px; display: flex; justify-content: center; align-items: center; min-height: 90vh; margin: 0; }
        .box { background: white; padding: 25px; border-radius: 8px; width: 350px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        h3 { margin-top: 0; color: #333; text-align: center; }
        label { display: block; margin-bottom: 5px; color: #555; font-size: 14px; }
        input { width: 100%; padding: 8px; margin-bottom: 12px; box-sizing: border-box; border: 1px solid #ccc; border-radius: 4px; }
        button { width: 100%; padding: 10px; background: #ffc107; color: black; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 15px; }
        button:hover { background: #e0a800; }
        .error { color: red; font-size: 14px; margin-bottom: 10px; text-align: center; }
        .volver { display: block; text-align: center; margin-top: 15px; color: #007bff; text-decoration: none; }
        .volver:hover { text-decoration: underline; }
        .preview-img { display: block; margin: 0 auto 10px auto; width: 60px; height: 60px; object-fit: cover; border-radius: 4px; border: 1px solid #ccc; }
    </style>
</head>
<body>

<div class="box">
    <h3>Actualizar Producto</h3>
    
    <?php if (!empty($error)) { echo "<div class='error'>$error</div>"; } ?>

    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="id" value="<?php echo htmlspecialchars($producto['id']); ?>">

        <label>Producto:</label>
        <input type="text" name="nombre" value="<?php echo htmlspecialchars($producto['nombre']); ?>" required>

        <label>Stock:</label>
        <input type="number" name="stock" value="<?php echo htmlspecialchars($producto['stock']); ?>" required>

        <label>Precio Normal ($):</label>
        <input type="number" step="0.01" name="precio" value="<?php echo htmlspecialchars($producto['precio']); ?>" required>

        <label>Imagen Actual:</label>
        <img src="obtener_imagen.php?id=<?php echo $producto['id']; ?>" alt="Imagen Actual" class="preview-img">

        <label>Cambiar Imagen (Opcional):</label>
        <input type="file" name="imagen" accept="image/*">

        <label>Fecha y Hora:</label>
        <input type="text" name="fecha_creacion" value="<?php echo htmlspecialchars($producto['fecha_creacion']); ?>" required>

        <button type="submit">Actualizar</button>
    </form>
    
    <a href="productos.php" class="volver">Volver al catálogo</a>
</div>

</body>
</html>