<?php
session_start();
include 'componentes/conexion.php';

$sesion_iniciada = isset($_SESSION['usuario']);
$es_admin = $sesion_iniciada && ($_SESSION['rol'] === 'admin');
$rol_actual = $sesion_iniciada ? $_SESSION['rol'] : 'visitante';

// URL pública del sitio (se detecta sola). Si estás en localhost, el QR apuntará a localhost
// y NO abrirá desde el celular; para probar usa la IP de tu PC (ej. http://192.168.1.50/proEs).
$esquema  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$carpeta  = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$base_url = $esquema . '://' . $_SERVER['HTTP_HOST'] . $carpeta;

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
        unset($prod); // evita bugs por la referencia abierta
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

        /* Modal de detalle de producto */
        .fila-producto { cursor: pointer; }
        .modal-prod { display: none; position: fixed; z-index: 20; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); justify-content: center; align-items: center; }
        .modal-prod-box { background: white; border-radius: 12px; padding: 30px; width: 90%; max-width: 900px; max-height: 90vh; overflow-y: auto; position: relative; display: flex; gap: 30px; flex-wrap: wrap; box-sizing: border-box; }
        .modal-prod-img { flex: 1 1 320px; background: #eee; border-radius: 8px; border: 1px solid #ddd; display: flex; align-items: center; justify-content: center; }
        .modal-prod-img img { max-width: 100%; max-height: 350px; object-fit: contain; }
        .modal-prod-info { flex: 1 1 280px; }
        .modal-prod-info h2 { color: #1976d2; margin-top: 0; }
        .modal-prod-precio { color: #2e7d32; font-size: 24px; font-weight: bold; margin: 10px 0; }
        .modal-prod-stock { color: #555; font-weight: bold; margin-bottom: 15px; }
        .qr-box { width: 160px; margin: 10px auto 5px; padding: 12px; border: 1px solid #ddd; border-radius: 8px; display: flex; justify-content: center; box-sizing: border-box; }
        .qr-texto { text-align: center; color: #777; font-size: 13px; margin-bottom: 15px; }
        .modal-cerrar { position: absolute; top: 10px; right: 18px; font-size: 28px; color: #999; cursor: pointer; border: none; background: none; }
        .btn-anadir { width: 100%; padding: 12px; background: #4caf50; color: white; border: none; border-radius: 6px; font-size: 17px; font-weight: bold; cursor: pointer; }
        .btn-anadir:hover { background: #43a047; }
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
                    <tr class="fila-producto"
                        data-id="<?php echo (int)$p['id']; ?>"
                        data-nombre="<?php echo htmlspecialchars($p['nombre']); ?>"
                        data-precio="<?php echo (float)$precio_normal; ?>"
                        data-stock="<?php echo htmlspecialchars($p['stock']); ?>">
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

<!-- Modal de confirmación para eliminar -->
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

<!-- Modal de detalle de producto con QR -->
<div id="modalProducto" class="modal-prod">
    <div class="modal-prod-box">
        <button type="button" class="modal-cerrar" onclick="cerrarProducto()">&times;</button>

        <div class="modal-prod-img">
            <img id="mpImg" src="" alt="Producto">
        </div>

        <div class="modal-prod-info">
            <h2 id="mpNombre"></h2>
            <div class="modal-prod-precio" id="mpPrecio"></div>
            <div class="modal-prod-stock" id="mpStock"></div>

            <div class="qr-box"><div id="mpQR"></div></div>
            <div class="qr-texto">Escanea para ver precio con y sin IVA</div>

            <form method="POST">
                <input type="hidden" name="accion" value="agregar">
                <input type="hidden" name="id" id="mpId">
                <input type="hidden" name="nombre" id="mpNombreH">
                <input type="hidden" name="precio" id="mpPrecioH">
                <button type="submit" class="btn-anadir">Añadir al Carrito</button>
            </form>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
    const BASE_URL = <?php echo json_encode($base_url); ?>;

    // ----- Modal de eliminar -----
    function mostrarModal(id) {
        const modal = document.getElementById('modalConfirmacion');
        const linkSi = document.getElementById('linkSi');
        linkSi.href = 'eliminar_producto.php?id=' + id;
        modal.style.display = 'flex';
    }

    function ocultarModal() {
        document.getElementById('modalConfirmacion').style.display = 'none';
    }

    // ----- Modal de detalle con QR -----
    document.querySelectorAll('.fila-producto').forEach(fila => {
        fila.addEventListener('click', function (e) {
            // No abrir el modal si se hizo click en botones, links o formularios
            if (e.target.closest('button, a, form')) return;

            const id = this.dataset.id;
            const nombre = this.dataset.nombre;
            const precio = parseFloat(this.dataset.precio);
            const stock = this.dataset.stock;

            document.getElementById('mpImg').src = 'obtener_imagen.php?id=' + id;
            document.getElementById('mpNombre').textContent = nombre;
            document.getElementById('mpPrecio').textContent = '$' + precio.toFixed(2);
            document.getElementById('mpStock').textContent = 'Stock Disponible: ' + stock;

            document.getElementById('mpId').value = id;
            document.getElementById('mpNombreH').value = nombre;
            document.getElementById('mpPrecioH').value = precio;

            // Generar QR que apunta a ver_producto.php
            const contenedor = document.getElementById('mpQR');
            contenedor.innerHTML = '';
            new QRCode(contenedor, {
                text: BASE_URL + '/ver_producto.php?id=' + id,
                width: 136,
                height: 136
            });

            document.getElementById('modalProducto').style.display = 'flex';
        });
    });

    function cerrarProducto() {
        document.getElementById('modalProducto').style.display = 'none';
    }

    // Cerrar al hacer click fuera de la caja
    document.getElementById('modalProducto').addEventListener('click', function (e) {
        if (e.target === this) cerrarProducto();
    });

    // Cerrar con la tecla Escape
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') cerrarProducto();
    });
</script>

</body>
</html>