<?php
session_start();
include 'componentes/conexion.php';

$sesion_iniciada = isset($_SESSION['usuario']);
$es_admin = $sesion_iniciada && ($_SESSION['rol'] === 'admin');
$rol_actual = $sesion_iniciada ? $_SESSION['rol'] : 'visitante';

// URL pública del sitio (se detecta sola). En localhost el QR apuntará a localhost y NO abrirá
// desde el celular; para probar usa la IP de tu PC (ej. http://192.168.1.50/proEs/productos.php).
$esquema  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$carpeta  = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$base_url = $esquema . '://' . $_SERVER['HTTP_HOST'] . $carpeta;

// Lógica para añadir elementos al carrito mediante POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'agregar') {
    $id = (int)$_POST['id'];

    // El nombre y el precio se leen de la base de datos (no se confía en lo que envía el navegador)
    $stmt_p = $conexion->prepare("SELECT nombre, precio, stock FROM productos WHERE id = :id");
    $stmt_p->execute([':id' => $id]);
    $prod_db = $stmt_p->fetch(PDO::FETCH_ASSOC);

    if (!$prod_db || (int)$prod_db['stock'] <= 0) {
        header("Location: productos.php");
        exit();
    }

    $nombre = $prod_db['nombre'];
    $precio = $prod_db['precio'];

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

$total_carrito = isset($_SESSION['carrito']) ? count($_SESSION['carrito']) : 0;
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TechPCs | Catálogo de Productos</title>
    <style>
        :root {
            --fondo: #f1f5f9;
            --tarjeta: #ffffff;
            --oscuro: #0f172a;
            --texto: #1e293b;
            --suave: #64748b;
            --borde: #e2e8f0;
            --azul: #2563eb;
            --azul-oscuro: #1d4ed8;
            --verde: #16a34a;
            --rojo: #dc2626;
            --amarillo: #f59e0b;
        }
        * { box-sizing: border-box; }
        body { font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; background: var(--fondo); margin: 0; color: var(--texto); }

        /* Barra superior */
        .navbar { background: var(--oscuro); color: white; padding: 14px 28px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; position: sticky; top: 0; z-index: 5; }
        .marca { font-size: 24px; font-weight: 800; letter-spacing: 0.5px; text-decoration: none; color: white; }
        .marca span { color: #3b82f6; }
        .nav-der { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .rol { font-size: 12px; background: rgba(255,255,255,0.12); padding: 5px 10px; border-radius: 20px; text-transform: capitalize; margin-right: 6px; }

        .btn { padding: 8px 14px; border-radius: 8px; text-decoration: none; font-size: 14px; font-weight: 600; cursor: pointer; color: white; display: inline-flex; align-items: center; gap: 6px; border: none; transition: background .15s, opacity .15s; }
        .btn:hover { opacity: 0.88; }
        .btn-carrito { background: var(--azul); }
        .btn-carrito .cnt { background: white; color: var(--azul); border-radius: 20px; padding: 1px 8px; font-size: 12px; }
        .btn-gris { background: rgba(255,255,255,0.14); }
        .btn-crear { background: var(--verde); }
        .btn-editar { background: var(--amarillo); color: #1e293b; padding: 6px 10px; font-size: 13px; }
        .btn-eliminar { background: var(--rojo); padding: 6px 10px; font-size: 13px; }
        .btn-agregar { background: var(--verde); padding: 7px 14px; }
        .btn-agregar:disabled { background: #cbd5e1; color: #64748b; cursor: not-allowed; }

        /* Contenido */
        .container { max-width: 1150px; margin: 28px auto; padding: 0 20px; }
        .panel { background: var(--tarjeta); border-radius: 14px; box-shadow: 0 1px 3px rgba(15,23,42,0.08), 0 8px 24px rgba(15,23,42,0.05); overflow: hidden; }
        .panel-top { padding: 22px 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px; border-bottom: 1px solid var(--borde); }
        .panel-top h1 { margin: 0; font-size: 22px; }
        .panel-top p { margin: 4px 0 0; color: var(--suave); font-size: 14px; }
        .acciones-top { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .buscador { padding: 10px 14px; border: 1px solid var(--borde); border-radius: 8px; font-size: 14px; width: 250px; background: #f8fafc; outline: none; }
        .buscador:focus { border-color: var(--azul); background: white; box-shadow: 0 0 0 3px rgba(37,99,235,0.15); }

        /* Tabla */
        .tabla-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        thead th { background: #f8fafc; color: var(--suave); font-size: 12px; text-transform: uppercase; letter-spacing: 0.6px; padding: 14px 18px; text-align: left; border-bottom: 1px solid var(--borde); white-space: nowrap; }
        tbody td { padding: 14px 18px; border-bottom: 1px solid var(--borde); vertical-align: middle; font-size: 15px; }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr.fila-producto { cursor: pointer; transition: background .12s; }
        tbody tr.fila-producto:hover { background: #f1f5f9; }
        .col-id { color: var(--suave); font-weight: 600; width: 60px; }
        .img-producto { width: 64px; height: 64px; object-fit: cover; border-radius: 10px; border: 1px solid var(--borde); background: #f8fafc; display: block; }
        .nombre-prod { font-weight: 600; }
        .precio { font-weight: 700; white-space: nowrap; }
        .badge { display: inline-block; padding: 4px 11px; border-radius: 20px; font-size: 12px; font-weight: 700; white-space: nowrap; }
        .badge.ok { background: #dcfce7; color: #15803d; }
        .badge.bajo { background: #fef3c7; color: #b45309; }
        .badge.agotado { background: #fee2e2; color: #b91c1c; }
        .acciones-fila { display: flex; gap: 6px; }
        .vacio { text-align: center; color: var(--suave); padding: 40px 10px !important; }

        /* Modales */
        .modal-confirm { display: none; position: fixed; z-index: 30; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(15,23,42,0.55); justify-content: center; align-items: center; }
        .modal-content { background: white; padding: 24px; border-radius: 14px; text-align: center; width: 320px; box-shadow: 0 10px 30px rgba(0,0,0,0.25); }
        .modal-content h3 { margin-top: 0; }
        .modal-buttons { margin-top: 18px; display: flex; justify-content: space-around; }
        .btn-si { background: var(--rojo); padding: 9px 24px; color: white; border-radius: 8px; text-decoration: none; font-weight: 700; }
        .btn-no { background: #64748b; padding: 9px 24px; color: white; border-radius: 8px; font-weight: 700; cursor: pointer; border: none; font-size: 14px; }

        .modal-prod { display: none; position: fixed; z-index: 20; left: 0; top: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.6); justify-content: center; align-items: center; padding: 15px; }
        .modal-prod-box { background: white; border-radius: 16px; padding: 30px; width: 100%; max-width: 900px; max-height: 92vh; overflow-y: auto; position: relative; display: flex; gap: 30px; flex-wrap: wrap; }
        .modal-prod-img { flex: 1 1 320px; background: #f1f5f9; border-radius: 12px; border: 1px solid var(--borde); display: flex; align-items: center; justify-content: center; min-height: 250px; }
        .modal-prod-img img { max-width: 100%; max-height: 360px; object-fit: contain; }
        .modal-prod-info { flex: 1 1 280px; }
        .modal-prod-info h2 { color: var(--azul); margin: 0 0 6px; padding-right: 25px; }
        .modal-prod-precio { color: #15803d; font-size: 28px; font-weight: 800; margin: 8px 0; }
        .modal-prod-stock { margin-bottom: 10px; }
        .qr-box { width: 164px; margin: 10px auto 6px; padding: 12px; border: 1px solid var(--borde); border-radius: 10px; display: flex; justify-content: center; }
        .qr-texto { text-align: center; color: var(--suave); font-size: 13px; margin-bottom: 16px; }
        .modal-cerrar { position: absolute; top: 10px; right: 16px; font-size: 30px; color: #94a3b8; cursor: pointer; border: none; background: none; line-height: 1; }
        .modal-cerrar:hover { color: #475569; }
        .btn-anadir { width: 100%; padding: 13px; background: var(--verde); color: white; border: none; border-radius: 10px; font-size: 17px; font-weight: 700; cursor: pointer; }
        .btn-anadir:hover { opacity: 0.9; }
        .btn-anadir:disabled { background: #cbd5e1; color: #64748b; cursor: not-allowed; }

        @media (max-width: 640px) {
            .navbar { padding: 12px 16px; }
            .buscador { width: 100%; }
            .acciones-top { width: 100%; }
        }
    </style>
</head>
<body>

<nav class="navbar">
    <a href="productos.php" class="marca">Tech<span>PCs</span></a>
    <div class="nav-der">
        <span class="rol">Rol: <?php echo htmlspecialchars($rol_actual); ?></span>
        <a href="carrito.php" class="btn btn-carrito">🛒 Carrito <span class="cnt"><?php echo $total_carrito; ?></span></a>
        <?php if ($es_admin): ?>
            <a href="panel_admin.php" class="btn btn-gris">Panel Admin</a>
            <a href="logout.php" class="btn btn-gris">Cerrar Sesión</a>
        <?php elseif ($sesion_iniciada): ?>
            <a href="index.php" class="btn btn-gris">Inicio</a>
            <a href="logout.php" class="btn btn-gris">Cerrar Sesión</a>
        <?php else: ?>
            <a href="index.php" class="btn btn-gris">Iniciar Sesión</a>
            <a href="registro.php" class="btn btn-crear">Registrarse</a>
        <?php endif; ?>
    </div>
</nav>

<div class="container">
    <div class="panel">
        <div class="panel-top">
            <div>
                <h1><?php echo $es_admin ? 'Inventario de productos' : 'Catálogo de productos'; ?></h1>
                <p><span id="contador"><?php echo count($productos); ?></span> productos · Haz click en uno para ver el detalle y su QR</p>
            </div>
            <div class="acciones-top">
                <input type="search" id="buscador" class="buscador" placeholder="Buscar producto...">
                <?php if ($es_admin): ?>
                    <a href="crear_producto.php" class="btn btn-crear">+ Nuevo producto</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="tabla-wrap">
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
                            $stock_n = (int)$p['stock'];
                            if ($stock_n <= 0) {
                                $clase_stock = 'agotado'; $texto_stock = 'Agotado';
                            } elseif ($stock_n <= 5) {
                                $clase_stock = 'bajo'; $texto_stock = 'Quedan ' . $stock_n;
                            } else {
                                $clase_stock = 'ok'; $texto_stock = $stock_n . ' en stock';
                            }
                        ?>
                            <tr class="fila-producto"
                                data-id="<?php echo (int)$p['id']; ?>"
                                data-nombre="<?php echo htmlspecialchars($p['nombre']); ?>"
                                data-precio="<?php echo (float)$precio_normal; ?>"
                                data-stock="<?php echo $stock_n; ?>">
                                <td class="col-id">#<?php echo htmlspecialchars($p['id']); ?></td>
                                <td>
                                    <img src="obtener_imagen.php?id=<?php echo (int)$p['id']; ?>" alt="Imagen de <?php echo htmlspecialchars($p['nombre']); ?>" class="img-producto" loading="lazy">
                                </td>
                                <td class="nombre-prod"><?php echo htmlspecialchars($p['nombre']); ?></td>
                                <td><span class="badge <?php echo $clase_stock; ?>"><?php echo $texto_stock; ?></span></td>
                                <td class="precio">$<?php echo number_format($precio_normal, 2); ?></td>
                                <td>
                                    <form method="POST" style="margin:0;">
                                        <input type="hidden" name="accion" value="agregar">
                                        <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                        <input type="hidden" name="nombre" value="<?php echo htmlspecialchars($p['nombre']); ?>">
                                        <input type="hidden" name="precio" value="<?php echo $precio_normal; ?>">
                                        <button type="submit" class="btn btn-agregar" <?php echo $stock_n <= 0 ? 'disabled' : ''; ?>>Agregar</button>
                                    </form>
                                </td>
                                <?php if ($es_admin): ?>
                                    <td>
                                        <div class="acciones-fila">
                                            <a href="editar_producto.php?id=<?php echo (int)$p['id']; ?>" class="btn btn-editar">Actualizar</a>
                                            <button type="button" onclick="mostrarModal(<?php echo (int)$p['id']; ?>)" class="btn btn-eliminar">Eliminar</button>
                                        </div>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?php echo $es_admin ? '7' : '6'; ?>" class="vacio">No hay productos registrados.</td>
                        </tr>
                    <?php endif; ?>
                    <tr id="sinResultados" style="display:none;">
                        <td colspan="<?php echo $es_admin ? '7' : '6'; ?>" class="vacio">No se encontraron productos con esa búsqueda.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal de confirmación para eliminar -->
<div id="modalConfirmacion" class="modal-confirm">
    <div class="modal-content">
        <h3>¿Estás seguro?</h3>
        <p>¿Deseas eliminar este producto?</p>
        <div class="modal-buttons">
            <a id="linkSi" href="#" class="btn-si">Sí</a>
            <button type="button" onclick="ocultarModal()" class="btn-no">No</button>
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
            <div class="modal-prod-stock"><span id="mpStock" class="badge ok"></span></div>

            <div class="qr-box"><div id="mpQR"></div></div>
            <div class="qr-texto">Escanea para ver precio con y sin IVA</div>

            <form method="POST">
                <input type="hidden" name="accion" value="agregar">
                <input type="hidden" name="id" id="mpId">
                <input type="hidden" name="nombre" id="mpNombreH">
                <input type="hidden" name="precio" id="mpPrecioH">
                <button type="submit" class="btn-anadir" id="mpBtn">Añadir al Carrito</button>
            </form>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
    const BASE_URL = <?php echo json_encode($base_url); ?>;

    // ----- Modal de eliminar -----
    function mostrarModal(id) {
        document.getElementById('linkSi').href = 'eliminar_producto.php?id=' + id;
        document.getElementById('modalConfirmacion').style.display = 'flex';
    }
    function ocultarModal() {
        document.getElementById('modalConfirmacion').style.display = 'none';
    }

    // ----- Buscador -----
    const filas = document.querySelectorAll('.fila-producto');
    document.getElementById('buscador').addEventListener('input', function () {
        const q = this.value.trim().toLowerCase();
        let visibles = 0;
        filas.forEach(f => {
            const coincide = f.dataset.nombre.toLowerCase().includes(q);
            f.style.display = coincide ? '' : 'none';
            if (coincide) visibles++;
        });
        document.getElementById('contador').textContent = visibles;
        document.getElementById('sinResultados').style.display = (filas.length > 0 && visibles === 0) ? '' : 'none';
    });

    // ----- Modal de detalle con QR -----
    filas.forEach(fila => {
        fila.addEventListener('click', function (e) {
            // No abrir el modal si se hizo click en botones, links o formularios
            if (e.target.closest('button, a, form')) return;

            const id = this.dataset.id;
            const nombre = this.dataset.nombre;
            const precio = parseFloat(this.dataset.precio);
            const stock = parseInt(this.dataset.stock, 10);

            document.getElementById('mpImg').src = 'obtener_imagen.php?id=' + id;
            document.getElementById('mpNombre').textContent = nombre;
            document.getElementById('mpPrecio').textContent = '$' + precio.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

            const badge = document.getElementById('mpStock');
            if (stock <= 0) {
                badge.className = 'badge agotado'; badge.textContent = 'Agotado';
            } else if (stock <= 5) {
                badge.className = 'badge bajo'; badge.textContent = 'Stock disponible: ' + stock;
            } else {
                badge.className = 'badge ok'; badge.textContent = 'Stock disponible: ' + stock;
            }
            document.getElementById('mpBtn').disabled = stock <= 0;

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
    document.getElementById('modalProducto').addEventListener('click', function (e) {
        if (e.target === this) cerrarProducto();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { cerrarProducto(); ocultarModal(); }
    });
</script>

</body>
</html>