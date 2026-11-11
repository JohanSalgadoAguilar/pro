<?php
session_start();
include 'componentes/conexion.php';
date_default_timezone_set('America/Mexico_City');

// SEGURIDAD: solo administradores
if (!isset($_SESSION['usuario']) || $_SESSION['rol'] !== 'admin') {
    header("Location: index.php");
    exit();
}

// Valida que la fecha tenga formato AAAA-MM-DD y sea real
function fecha_valida($f) {
    $d = DateTime::createFromFormat('Y-m-d', (string)$f);
    return ($d && $d->format('Y-m-d') === $f) ? $f : null;
}

// Evita que Excel interprete texto como fórmula
function csv_seguro($t) {
    $t = (string)$t;
    return (strlen($t) > 0 && strpos("=+-@", $t[0]) !== false) ? "'" . $t : $t;
}

$hoy = date('Y-m-d');
$desde = fecha_valida($_GET['desde'] ?? '') ?? date('Y-m-01');
$hasta = fecha_valida($_GET['hasta'] ?? '') ?? $hoy;
if ($desde > $hasta) {
    [$desde, $hasta] = [$hasta, $desde];
}

// Ventas del periodo con su detalle
$stmt = $conexion->prepare("
    SELECT v.id, v.usuario, v.subtotal, v.iva, v.total, v.fecha_venta,
           d.nombre_producto, d.cantidad, d.precio_unitario, d.subtotal AS subtotal_linea
    FROM ventas v
    JOIN detalle_ventas d ON d.venta_id = v.id
    WHERE v.fecha_venta >= :desde AND v.fecha_venta < DATE_ADD(:hasta, INTERVAL 1 DAY)
    ORDER BY v.fecha_venta DESC, v.id DESC, d.id ASC
");
$stmt->execute([':desde' => $desde, ':hasta' => $hasta]);
$filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ventas = [];
$top = [];
$unidades = 0;
foreach ($filas as $f) {
    $id = $f['id'];
    if (!isset($ventas[$id])) {
        $ventas[$id] = [
            'id' => $id,
            'usuario' => $f['usuario'],
            'fecha' => $f['fecha_venta'],
            'subtotal' => (float)$f['subtotal'],
            'iva' => (float)$f['iva'],
            'total' => (float)$f['total'],
            'items' => []
        ];
    }
    $ventas[$id]['items'][] = [
        'nombre' => $f['nombre_producto'],
        'cantidad' => (int)$f['cantidad'],
        'precio' => (float)$f['precio_unitario'],
        'subtotal' => (float)$f['subtotal_linea']
    ];
    $unidades += (int)$f['cantidad'];

    $n = $f['nombre_producto'];
    if (!isset($top[$n])) { $top[$n] = ['unidades' => 0, 'importe' => 0]; }
    $top[$n]['unidades'] += (int)$f['cantidad'];
    $top[$n]['importe'] += (float)$f['subtotal_linea'];
}
uasort($top, function ($a, $b) { return $b['unidades'] <=> $a['unidades']; });
$top = array_slice($top, 0, 5, true);

$total_ventas = count($ventas);
$suma_subtotal = array_sum(array_column($ventas, 'subtotal'));
$suma_iva = array_sum(array_column($ventas, 'iva'));
$suma_total = array_sum(array_column($ventas, 'total'));
$ticket_promedio = $total_ventas > 0 ? $suma_total / $total_ventas : 0;

// Exportar a CSV (se abre en Excel)
if (isset($_GET['exportar']) && $_GET['exportar'] === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="reporte_ventas_' . $desde . '_a_' . $hasta . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM para que Excel respete los acentos
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Folio', 'Fecha', 'Cliente', 'Producto', 'Cantidad', 'Precio unitario (sin IVA)', 'Subtotal (sin IVA)'], ',', '"', '\\');
    foreach ($ventas as $v) {
        foreach ($v['items'] as $it) {
            fputcsv($out, [
                $v['id'], $v['fecha'], csv_seguro($v['usuario']), csv_seguro($it['nombre']),
                $it['cantidad'], number_format($it['precio'], 2, '.', ''), number_format($it['subtotal'], 2, '.', '')
            ], ',', '"', '\\');
        }
    }
    fclose($out);
    exit();
}

// Escaneos de QR por producto en el periodo (incluye productos con 0 escaneos)
$escaneos = [];
$total_escaneos = 0;
try {
    $stmt_qr = $conexion->prepare("
        SELECT p.id, p.nombre, COUNT(e.id) AS veces
        FROM productos p
        LEFT JOIN escaneos_qr e ON e.producto_id = p.id
            AND e.fecha_escaneo >= :desde AND e.fecha_escaneo < DATE_ADD(:hasta, INTERVAL 1 DAY)
        GROUP BY p.id, p.nombre
        ORDER BY veces DESC, p.nombre ASC
    ");
    $stmt_qr->execute([':desde' => $desde, ':hasta' => $hasta]);
    $escaneos = $stmt_qr->fetchAll(PDO::FETCH_ASSOC);
    $total_escaneos = array_sum(array_column($escaneos, 'veces'));
} catch (PDOException $e) {
    $escaneos = null; // la tabla escaneos_qr aún no existe
}

// Atajos de periodo
$rangos = [
    'Hoy'            => [$hoy, $hoy],
    'Últimos 7 días' => [date('Y-m-d', strtotime('-6 days')), $hoy],
    'Este mes'       => [date('Y-m-01'), $hoy],
    'Mes anterior'   => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month'))],
];
$qs_actual = http_build_query(['desde' => $desde, 'hasta' => $hasta]);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TechPCs | Reporte de ventas</title>
    <link rel="stylesheet" href="componentes/estilos.css?v=1">
    <style>
        .filtros { display: flex; gap: 14px; align-items: flex-end; flex-wrap: wrap; }
        .filtros .form-group { margin: 0; }
        .atajos { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 14px; }
        .atajos a { padding: 6px 12px; border-radius: 20px; background: #f1f5f9; color: #475569; text-decoration: none; font-size: 13px; font-weight: 600; }
        .atajos a:hover, .atajos a.activo { background: #dbeafe; color: #1d4ed8; }
        .lista-items { margin: 0; padding: 0; list-style: none; font-size: 14px; }
        .lista-items li { padding: 1px 0; }
        .tabla tfoot td { font-weight: 800; background: #f8fafc; border-top: 2px solid var(--borde); }
        @media print {
            .no-print, .navbar { display: none !important; }
            body { background: #fff; }
            .panel, .stat { box-shadow: none; border: 1px solid #ddd; }
        }
    </style>
</head>
<body>

<nav class="navbar">
    <a href="panel_admin.php" class="marca">Tech<span>PCs</span></a>
    <div class="nav-der">
        <span class="rol">Admin: <?php echo htmlspecialchars($_SESSION['usuario']); ?></span>
        <a href="panel_admin.php" class="btn btn-gris">← Panel</a>
        <a href="logout.php" class="btn btn-gris">Cerrar sesión</a>
    </div>
</nav>

<div class="container">

    <div class="panel no-print">
        <div class="panel-top">
            <div>
                <h1>Reporte de ventas</h1>
                <p>Selecciona el periodo que quieres consultar</p>
            </div>
        </div>
        <div class="panel-body">
            <form method="GET" class="filtros">
                <div class="form-group">
                    <label for="desde">Desde</label>
                    <input type="date" id="desde" name="desde" value="<?php echo $desde; ?>" max="<?php echo $hoy; ?>" required>
                </div>
                <div class="form-group">
                    <label for="hasta">Hasta</label>
                    <input type="date" id="hasta" name="hasta" value="<?php echo $hasta; ?>" max="<?php echo $hoy; ?>" required>
                </div>
                <button type="submit" class="btn btn-azul">Generar reporte</button>
                <a href="reporte_ventas.php?<?php echo $qs_actual; ?>&exportar=csv" class="btn btn-verde">⬇ Exportar a Excel (CSV)</a>
                <button type="button" class="btn btn-oscuro" onclick="window.print()">🖨 Imprimir</button>
            </form>
            <div class="atajos">
                <?php foreach ($rangos as $nombre => $r): ?>
                    <a href="reporte_ventas.php?desde=<?php echo $r[0]; ?>&hasta=<?php echo $r[1]; ?>" class="<?php echo ($desde === $r[0] && $hasta === $r[1]) ? 'activo' : ''; ?>"><?php echo $nombre; ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <p style="color: var(--suave); margin: 0 0 14px;">
        Periodo: <strong>del <?php echo date('d/m/Y', strtotime($desde)); ?> al <?php echo date('d/m/Y', strtotime($hasta)); ?></strong>
    </p>

    <div class="stats">
        <div class="stat"><div class="n"><?php echo $total_ventas; ?></div><div class="l">Ventas realizadas</div></div>
        <div class="stat" style="border-color: #7c3aed;"><div class="n"><?php echo $unidades; ?></div><div class="l">Unidades vendidas</div></div>
        <div class="stat" style="border-color: var(--verde);"><div class="n">$<?php echo number_format($suma_total, 2); ?></div><div class="l">Total vendido (con IVA)</div></div>
        <div class="stat" style="border-color: var(--amarillo);"><div class="n">$<?php echo number_format($ticket_promedio, 2); ?></div><div class="l">Ticket promedio</div></div>
    </div>

    <?php if ($total_ventas > 0 && count($top) > 0): ?>
    <div class="panel">
        <div class="panel-top"><div><h1 style="font-size: 18px;">Productos más vendidos</h1></div></div>
        <div class="tabla-wrap">
            <table class="tabla">
                <thead>
                    <tr><th>Producto</th><th class="text-center">Unidades</th><th class="text-right">Importe (sin IVA)</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($top as $nombre => $t): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($nombre); ?></strong></td>
                            <td class="text-center"><?php echo $t['unidades']; ?></td>
                            <td class="text-right">$<?php echo number_format($t['importe'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="panel">
        <div class="panel-top">
            <div>
                <h1 style="font-size: 18px;">Escaneos de QR por producto</h1>
                <?php if ($escaneos !== null): ?><p><?php echo (int)$total_escaneos; ?> escaneo(s) en el periodo</p><?php endif; ?>
            </div>
        </div>
        <?php if ($escaneos === null): ?>
            <div class="panel-body" style="color: var(--suave);">Aún no existe la tabla <strong>escaneos_qr</strong>. Créala en phpMyAdmin para empezar a contar.</div>
        <?php else: ?>
        <div class="tabla-wrap">
            <table class="tabla">
                <thead>
                    <tr><th>Producto</th><th class="text-center">Veces escaneado</th></tr>
                </thead>
                <tbody>
                    <?php if (count($escaneos) > 0): ?>
                        <?php foreach ($escaneos as $e): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($e['nombre']); ?></strong></td>
                                <td class="text-center"><?php echo (int)$e['veces']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="2" class="text-center" style="padding: 30px; color: var(--suave);">No hay productos registrados.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <div class="panel">
        <div class="panel-top"><div><h1 style="font-size: 18px;">Detalle de ventas</h1></div></div>
        <div class="tabla-wrap">
            <table class="tabla">
                <thead>
                    <tr>
                        <th>Folio</th>
                        <th>Fecha</th>
                        <th>Cliente</th>
                        <th>Productos</th>
                        <th class="text-right">Subtotal</th>
                        <th class="text-right">IVA</th>
                        <th class="text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($total_ventas > 0): ?>
                        <?php foreach ($ventas as $v): ?>
                            <tr>
                                <td style="color: var(--suave); font-weight: 600;">#<?php echo (int)$v['id']; ?></td>
                                <td style="white-space: nowrap;"><?php echo date('d/m/Y H:i', strtotime($v['fecha'])); ?></td>
                                <td><strong><?php echo htmlspecialchars($v['usuario']); ?></strong></td>
                                <td>
                                    <ul class="lista-items">
                                        <?php foreach ($v['items'] as $it): ?>
                                            <li><?php echo $it['cantidad']; ?> × <?php echo htmlspecialchars($it['nombre']); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </td>
                                <td class="text-right">$<?php echo number_format($v['subtotal'], 2); ?></td>
                                <td class="text-right">$<?php echo number_format($v['iva'], 2); ?></td>
                                <td class="text-right"><strong>$<?php echo number_format($v['total'], 2); ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center" style="padding: 40px; color: var(--suave);">No hay ventas registradas en este periodo.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
                <?php if ($total_ventas > 0): ?>
                <tfoot>
                    <tr>
                        <td colspan="4">TOTALES DEL PERIODO</td>
                        <td class="text-right">$<?php echo number_format($suma_subtotal, 2); ?></td>
                        <td class="text-right">$<?php echo number_format($suma_iva, 2); ?></td>
                        <td class="text-right">$<?php echo number_format($suma_total, 2); ?></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>

</div>

</body>
</html>
