<?php
session_start();
include 'componentes/conexion.php';

// SEGURIDAD: Validar si el usuario está logueado y si es admin
if (!isset($_SESSION['usuario']) || $_SESSION['rol'] !== 'admin') {
    header("Location: index.php");
    exit();
}

// Lógica para ELIMINAR usuario
if (isset($_GET['eliminar'])) {
    $id_eliminar = $_GET['eliminar'];
    // Evitar que el admin se borre a sí mismo por accidente
    if ($id_eliminar != $_SESSION['id']) {
        $stmt_del = $conexion->prepare("DELETE FROM usuarios WHERE id = :id");
        $stmt_del->execute([':id' => $id_eliminar]);
    }
    header("Location: panel_admin.php");
    exit();
}

// Obtener todos los usuarios para la tabla
$stmt = $conexion->prepare("SELECT id, usuario, correo, rol, fecha_registro FROM usuarios");
$stmt->execute();
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Resumen
$total_usuarios = count($usuarios);
$total_admins = count(array_filter($usuarios, function ($u) { return $u['rol'] === 'admin'; }));
$total_clientes = $total_usuarios - $total_admins;

$stmt_prod = $conexion->query("SELECT COUNT(*) AS total, COALESCE(SUM(stock), 0) AS unidades FROM productos");
$res_prod = $stmt_prod->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TechPCs | Panel de administración</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="componentes/estilos.css?v=1">
</head>
<body>

<nav class="navbar">
    <a href="panel_admin.php" class="marca">Tech<span>PCs</span></a>
    <div class="nav-der">
        <span class="rol">Admin: <?php echo htmlspecialchars($_SESSION['usuario']); ?></span>
        <a href="productos.php" class="btn btn-gris">Ver tienda</a>
        <a href="logout.php" class="btn btn-gris">Cerrar sesión</a>
    </div>
</nav>

<div class="container">

    <div class="stats">
        <div class="stat"><div class="n"><?php echo $total_usuarios; ?></div><div class="l">Usuarios registrados</div></div>
        <div class="stat" style="border-color: var(--verde);"><div class="n"><?php echo $total_clientes; ?></div><div class="l">Clientes</div></div>
        <div class="stat" style="border-color: var(--amarillo);"><div class="n"><?php echo (int)$res_prod['total']; ?></div><div class="l">Productos</div></div>
        <div class="stat" style="border-color: #7c3aed;"><div class="n"><?php echo (int)$res_prod['unidades']; ?></div><div class="l">Unidades en stock</div></div>
    </div>

    <div class="panel">
        <div class="panel-top">
            <div>
                <h1>Gestión de usuarios</h1>
                <p><?php echo $total_admins; ?> administrador(es) · <?php echo $total_clientes; ?> cliente(s)</p>
            </div>
            <div class="acciones">
                <a href="crear_usuario_admin.php" class="btn btn-verde">+ Nuevo usuario</a>
                <a href="productos.php" class="btn btn-azul">Gestionar productos</a>
                <button type="button" class="btn btn-morado" onclick="abrirModalStock()">📊 Gráfica de stock</button>
                <a href="ver_bitacora.php" class="btn btn-cyan">Bitácora de accesos</a>
            </div>
        </div>

        <div class="tabla-wrap">
            <table class="tabla">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Usuario</th>
                        <th>Correo</th>
                        <th>Rol</th>
                        <th>Fecha de registro</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($usuarios as $u): ?>
                        <tr>
                            <td style="color: var(--suave); font-weight: 600;">#<?php echo htmlspecialchars($u['id']); ?></td>
                            <td><strong><?php echo htmlspecialchars($u['usuario']); ?></strong></td>
                            <td><?php echo htmlspecialchars($u['correo']); ?></td>
                            <td><span class="badge <?php echo $u['rol'] === 'admin' ? 'admin' : 'usuario'; ?>"><?php echo htmlspecialchars($u['rol']); ?></span></td>
                            <td><?php echo htmlspecialchars($u['fecha_registro']); ?></td>
                            <td>
                                <div class="acciones">
                                    <a href="editar_usuario.php?id=<?php echo (int)$u['id']; ?>" class="btn btn-amarillo btn-sm">Editar</a>
                                    <?php if ($u['id'] != $_SESSION['id']): ?>
                                        <a href="panel_admin.php?eliminar=<?php echo (int)$u['id']; ?>" class="btn btn-rojo btn-sm" onclick="return confirm('¿Estás seguro de eliminar este usuario?');">Eliminar</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Ventana para la gráfica de stock -->
<dialog id="modalStock" class="modal-stock">
    <div class="modal-header">
        <h3>Monitoreo de stock en tiempo real</h3>
        <button type="button" class="btn btn-oscuro btn-sm" onclick="cerrarModalStock()">Cerrar</button>
    </div>
    <div style="position: relative; height: 350px; width: 100%;">
        <canvas id="graficaStock"></canvas>
    </div>
</dialog>

<script>
let chartStock = null;
let intervaloStock = null;

// Inicializar la estructura de Chart.js
function inicializarGrafica() {
    const ctx = document.getElementById('graficaStock').getContext('2d');
    chartStock = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: [],
            datasets: [{
                label: 'Unidades en stock por producto',
                data: [],
                backgroundColor: 'rgba(37, 99, 235, 0.75)',
                borderColor: 'rgba(37, 99, 235, 1)',
                borderWidth: 1,
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { stepSize: 1 }
                }
            }
        }
    });
}

// Consultar el endpoint JSON y actualizar los datos
function actualizarGrafica() {
    fetch('obtener_stock_json.php')
        .then(response => response.json())
        .then(res => {
            if (res.status === 'success' && chartStock) {
                chartStock.data.labels = res.labels;
                chartStock.data.datasets[0].data = res.data;
                chartStock.update('none');
            }
        })
        .catch(err => console.error("Error al actualizar stock:", err));
}

function abrirModalStock() {
    document.getElementById('modalStock').showModal();
    actualizarGrafica();
    // Actualiza cada 3 segundos solo mientras la ventana esté abierta
    if (!intervaloStock) {
        intervaloStock = setInterval(actualizarGrafica, 3000);
    }
}

function cerrarModalStock() {
    document.getElementById('modalStock').close();
    if (intervaloStock) {
        clearInterval(intervaloStock);
        intervaloStock = null;
    }
}

// Si se cierra con la tecla Escape, también se detiene la actualización
document.getElementById('modalStock').addEventListener('close', function () {
    if (intervaloStock) {
        clearInterval(intervaloStock);
        intervaloStock = null;
    }
});

document.addEventListener('DOMContentLoaded', inicializarGrafica);
</script>

</body>
</html>