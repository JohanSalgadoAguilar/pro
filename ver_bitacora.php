<?php
session_start();
include 'componentes/conexion.php';

// SEGURIDAD: Verificar que esté logueado y sea administrador
if (!isset($_SESSION['usuario']) || $_SESSION['rol'] !== 'admin') {
    header("Location: index.php");
    exit();
}

// Consultar los registros de la bitácora ordenados del más reciente al más antiguo
$stmt = $conexion->prepare("
    SELECT 
        b.id, 
        b.usuario_id, 
        b.usuario_ingresado, 
        b.fecha_hora, 
        b.resultado,
        u.usuario AS usuario_registrado
    FROM bitacora_intentos b
    LEFT JOIN usuarios u ON b.usuario_id = u.id
    ORDER BY b.fecha_hora DESC
");
$stmt->execute();
$intentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Bitácora de Intentos de Inicio de Sesión</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #f4f4f9; margin: 0; padding: 20px; }
        .container { max-width: 950px; margin: auto; background: white; padding: 25px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        h2 { color: #333; margin-top: 0; }
        .header-flex { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #343a40; color: white; }
        tr:hover { background-color: #f1f1f1; }
        .btn { padding: 8px 14px; border-radius: 4px; text-decoration: none; font-size: 14px; cursor: pointer; color: white; display: inline-block; }
        .btn-volver { background: #6c757d; }
        .badge-exitoso { background-color: #d4edda; color: #155724; padding: 5px 10px; border-radius: 4px; font-weight: bold; }
        .badge-fallido { background-color: #f8d7da; color: #721c24; padding: 5px 10px; border-radius: 4px; font-weight: bold; }
        .btn:hover { opacity: 0.85; }
    </style>
</head>
<body>

<div class="container">
    <div class="header-flex">
        <h2>Bitácora de Intentos de Acceso</h2>
        <a href="panel_admin.php" class="btn btn-volver">Volver al Panel</a>
    </div>

    <table>
        <thead>
            <tr>
                <th>ID Intento</th>
                <th>ID Usuario</th>
                <th>Usuario Ingresado</th>
                <th>Fecha y Hora</th>
                <th>Resultado</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($intentos) > 0): ?>
                <?php foreach ($intentos as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['id']); ?></td>
                        <td><?php echo $row['usuario_id'] ? htmlspecialchars($row['usuario_id']) : '<em>N/A</em>'; ?></td>
                        <td><?php echo htmlspecialchars($row['usuario_ingresado']); ?></td>
                        <td><?php echo htmlspecialchars($row['fecha_hora']); ?></td>
                        <td>
                            <?php if ($row['resultado'] === 'exitoso'): ?>
                                <span class="badge-exitoso">Exitoso</span>
                            <?php else: ?>
                                <span class="badge-fallido">Fallido</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="5" style="text-align: center;">No hay registros en la bitácora todavía.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

</body>
</html>