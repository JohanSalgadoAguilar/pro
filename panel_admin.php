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
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Panel de Administración</title>
    <!-- Incluir Chart.js para la gráfica de barras -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: Arial, sans-serif; background-color: #f4f4f9; margin: 0; padding: 20px; }
        .container { max-width: 950px; margin: auto; background: white; padding: 25px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        h2 { color: #333; margin-top: 0; }
        .header-flex { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .action-buttons { margin-bottom: 20px; } /* Contenedor para los botones superiores */
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #343a40; color: white; }
        tr:hover { background-color: #f1f1f1; }
        .btn { padding: 8px 14px; border-radius: 4px; text-decoration: none; font-size: 14px; cursor: pointer; color: white; display: inline-block; margin-right: 5px; margin-bottom: 5px; border: none; }
        .btn-crear { background: #28a745; }
        .btn-productos { background: #ff851b; }
        .btn-stock { background: #6f42c1; } /* Estilo para el botón de la gráfica */
        .btn-bitacora { background: #17a2b8; }
        .btn-editar { background: #ffc107; color: black; padding: 6px 12px; }
        .btn-eliminar { background: #dc3545; padding: 6px 12px; }
        .btn-salir { background: #6c757d; }
        .btn:hover { opacity: 0.85; }

        /* Estilos para la ventana modal nativa de la gráfica */
        dialog.modal-stock {
            border: none;
            border-radius: 8px;
            padding: 20px;
            width: 700px;
            max-width: 90%;
            box-shadow: 0 5px 20px rgba(0,0,0,0.3);
        }
        dialog.modal-stock::backdrop {
            background: rgba(0, 0, 0, 0.5);
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        .modal-header h3 {
            margin: 0;
            color: #333;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header-flex">
        <h2>Panel de Control (Superusuario: <?php echo htmlspecialchars($_SESSION['usuario']); ?>)</h2>
        <a href="logout.php" class="btn btn-salir">Cerrar Sesión</a>
    </div>

    <!-- Botones superiores organizados -->
    <div class="action-buttons">
        <a href="crear_usuario_admin.php" class="btn btn-crear">+ Crear Nuevo Usuario</a>
        <a href="productos.php" class="btn btn-productos">Gestionar Productos</a>
        <button type="button" class="btn btn-stock" onclick="abrirModalStock()">📊 Ver Gráfica de Stock</button>
        <a href="ver_bitacora.php" class="btn btn-bitacora">Ver Bitácora de Intentos</a>
    </div>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Usuario</th>
                <th>Correo</th>
                <th>Rol</th>
                <th>Fecha de Registro</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($usuarios as $u): ?>
                <tr>
                    <td><?php echo htmlspecialchars($u['id']); ?></td>
                    <td><?php echo htmlspecialchars($u['usuario']); ?></td>
                    <td><?php echo htmlspecialchars($u['correo']); ?></td>
                    <td><strong><?php echo htmlspecialchars($u['rol']); ?></strong></td>
                    <td><?php echo htmlspecialchars($u['fecha_registro']); ?></td>
                    <td>
                        <a href="editar_usuario.php?id=<?php echo $u['id']; ?>" class="btn btn-editar">Editar</a>
                        <?php if ($u['id'] != $_SESSION['id']): ?>
                            <a href="panel_admin.php?eliminar=<?php echo $u['id']; ?>" class="btn btn-eliminar" onclick="return confirm('¿Estás seguro de eliminar este usuario?');">Eliminar</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Modal Nativo para la Gráfica de Stock -->
<dialog id="modalStock" class="modal-stock">
    <div class="modal-header">
        <h3>Monitoreo de Stock en Tiempo Real</h3>
        <button type="button" class="btn btn-salir" onclick="cerrarModalStock()">Cerrar</button>
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
                label: 'Unidades en Stock por Producto',
                data: [],
                backgroundColor: 'rgba(54, 162, 235, 0.7)',
                borderColor: 'rgba(54, 162, 235, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1
                    }
                }
            }
        }
    });
}

// Consultar el endpoint JSON y actualizar los datos sin parpadeos molestos
function actualizarGrafica() {
    fetch('obtener_stock_json.php')
        .then(response => response.json())
        .then(res => {
            if (res.status === 'success' && chartStock) {
                chartStock.data.labels = res.labels;
                chartStock.data.datasets[0].data = res.data;
                chartStock.update('none'); // Actualización fluida en tiempo real
            }
        })
        .catch(err => console.error("Error al actualizar stock:", err));
}

// Funciones para abrir y cerrar el modal controlando el bucle de actualización
function abrirModalStock() {
    const modal = document.getElementById('modalStock');
    modal.showModal();
    actualizarGrafica(); // Carga inmediata al abrir
    
    // Configurar actualización cada 3 segundos solo mientras el modal esté abierto
    if (!intervaloStock) {
        intervaloStock = setInterval(actualizarGrafica, 3000);
    }
}

function cerrarModalStock() {
    const modal = document.getElementById('modalStock');
    modal.close();
    
    // Detener el intervalo cuando se cierre el modal para ahorrar recursos
    if (intervaloStock) {
        clearInterval(intervaloStock);
        intervaloStock = null;
    }
}

// Inicializar la instancia de la gráfica al cargar la página
document.addEventListener('DOMContentLoaded', () => {
    inicializarGrafica();
});
</script>

</body>
</html>