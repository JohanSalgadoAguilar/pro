<?php
session_start();
include 'componentes/conexion.php';

$sesion_iniciada = isset($_SESSION['usuario']);
$es_admin = $sesion_iniciada && ($_SESSION['rol'] === 'admin');
$rol_actual = $sesion_iniciada ? $_SESSION['rol'] : 'visitante';

// Lógica para añadir elementos al carrito mediante POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'agregar') {
    $id = $_POST['id'];
    $nombre = $_POST['nombre'];
    $precio = $_POST['precio'];

    $item = [
        'id' => $id,
        'nombre' => $nombre,
        'precio' => $precio,
        'cantidad' => 1
    ];

    $encontrado = false;
    if (isset($_SESSION['carrito'])) {
        foreach ($_SESSION['carrito'] as &$prod) {
            if ($prod['id'] == $id) {
                $prod['cantidad']++;
                $encontrado = true;
                break;
            }
        }
    }

    if (!$encontrado) {
        $_SESSION['carrito'][] = $item;
    }
    header("Location: productos.php");
    exit();
}

if ($es_admin && isset($_GET['eliminar'])) {
    $id_eliminar = $_GET['eliminar'];
    $stmt_del = $conexion->prepare("DELETE FROM productos WHERE id = :id");
    $stmt_del->execute([':id' => $id_eliminar]);
    header("Location: productos.php");
    exit();
}

// Consultar productos incluyendo el campo precio
$stmt = $conexion->prepare("SELECT id, nombre, stock, precio, fecha_creacion FROM productos");
$stmt->execute();
$productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Catálogo de Productos</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #f4f4f9; margin: 0; padding: 20px; }
        .container { max-width: 1050px; margin: auto; background: white; padding: 25px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .header-flex { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; vertical-align: middle; }
        th { background-color: #343a40; color: white; }
        tr:hover { background-color: #f1f1f1; }
        .btn { padding: 6px 12px; border-radius: 4px; text-decoration: none; font-size: 14px; cursor: pointer; color: white; display: inline-block; border: none; }
        .btn-crear { background: #28a745; margin-bottom: 15px; }
        .btn-editar { background: #ffc107; color: black; }
        .btn-eliminar { background: #dc3545; }
        .btn-volver { background: #6c757d; }
        .btn-login { background: #007bff; }
        .btn-carrito { background: #17a2b8; }
        .img-producto { width: 50px; height: 50px; object-fit: cover; border-radius: 4px; border: 1px solid #ccc; }
        .btn:hover { opacity: 0.85; }

        .modal-confirm { display: none; position: fixed; z-index: 10; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); justify-content: center; align-items: center; }
        .modal-content { background: white; padding: 20px; border-radius: 8px; text-align: center; width: 300px; box-shadow: 0 4px 15px rgba(0,0,0,0.2); }
        .modal-buttons { margin-top: 15px; display: flex; justify-content: space-around; }
        .btn-si { background: #dc3545; padding: 8px 20px; color: white; border-radius: 4px; text-decoration: none; font-weight: bold; }
        .btn-no { background: #6c757d; padding: 8px 20px; color: white; border-radius: 4px; text-decoration: none; font-weight: bold; cursor: pointer; border: none; }
    </style>
</head>
<body>

<div class="container">
    <div class="header-flex">
        <h2>Catálogo de Productos (Rol: <?php echo htmlspecialchars($rol_actual); ?>)</h2>
        <div>
            <a href="carrito.php" class="btn btn-carrito">Ver Carrito (<?php echo isset($_SESSION['carrito']) ? count($_SESSION['carrito']) : 0; ?>)</a>
            <?php if ($es_admin): ?>
                <a href="panel_admin.php" class="btn btn-volver">Panel Admin</a>
                <a href="logout.php" class="btn" style="background: #343a40;">Cerrar Sesión</a>
            <?php elseif ($sesion_iniciada): ?>
                <a href="index.php" class="btn btn-volver">Inicio</a>
                <a href="logout.php" class="btn" style="background: #343a40;">Cerrar Sesión</a>
            <?php else: ?>
                <a href="index.php" class="btn btn-login">Iniciar Sesión</a>
                <a href="registro.php" class="btn btn-crear" style="margin-bottom:0;">Registrarse</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($es_admin): ?>
        <a href="crear_producto.php" class="btn btn-crear">+ Añadir Nuevo Producto</a>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Imagen</th>
                <th>Producto</th>
                <th>Stock</th>
                <th>Precio</th>
                <th>Comprar</th>
                <?php if ($es_admin): ?>
                    <th>Acciones</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (count($productos) > 0): ?>
                <?php foreach ($productos as $p): 
                    $precio_normal = $p['precio'];
                ?>
                    <tr>
                        <td><?php echo htmlspecialchars($p['id']); ?></td>
                        <td>
                            <img src="obtener_imagen.php?id=<?php echo $p['id']; ?>" alt="Imagen BLOB" class="img-producto">
                        </td>
                        <td><?php echo htmlspecialchars($p['nombre']); ?></td>
                        <td><?php echo htmlspecialchars($p['stock']); ?></td>
                        <td>$<?php echo number_format($precio_normal, 2); ?></td>
                        <td>
                            <form method="POST">
                                <input type="hidden" name="accion" value="agregar">
                                <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                <input type="hidden" name="nombre" value="<?php echo htmlspecialchars($p['nombre']); ?>">
                                <input type="hidden" name="precio" value="<?php echo $precio_normal; ?>">
                                <button type="submit" class="btn" style="background: #28a745;">Agregar</button>
                            </form>
                        </td>
                        <?php if ($es_admin): ?>
                            <td>
                                <a href="editar_producto.php?id=<?php echo $p['id']; ?>" class="btn btn-editar">Actualizar</a>
                                <button onclick="mostrarModal(<?php echo $p['id']; ?>)" class="btn btn-eliminar">Eliminar</button>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="<?php echo $es_admin ? '7' : '6'; ?>" style="text-align: center;">No hay productos registrados.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div id="modalConfirmacion" class="modal-confirm">
    <div class="modal-content">
        <h3>¿Estás seguro?</h3>
        <p>¿Deseas eliminar este producto?</p>
        <div class="modal-buttons">
            <a id="linkSi" href="#" class="btn-si">Sí</a>
            <button onclick="ocultarModal()" class="btn-no">No</button>
        </div>
    </div>
</div>

<script>
    function mostrarModal(id) {
        const modal = document.getElementById('modalConfirmacion');
        const linkSi = document.getElementById('linkSi');
        linkSi.href = 'eliminar_producto.php?id=' + id;
        modal.style.display = 'flex';
    }

    function ocultarModal() {
        const modal = document.getElementById('modalConfirmacion');
        modal.style.display = 'none';
    }
</script>

</body>
</html>