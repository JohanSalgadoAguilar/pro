<?php
// Página pública (sin sesión) a la que apunta el QR
include 'componentes/conexion.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $conexion->prepare("SELECT id, nombre, stock, precio FROM productos WHERE id = :id");
$stmt->execute([':id' => $id]);
$p = $stmt->fetch(PDO::FETCH_ASSOC);

// CONTADOR DE ESCANEOS: solo cuenta las aperturas que vienen del QR (?src=qr) y no cuenta robots
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$es_robot = ($ua === '' || preg_match('/bot|crawl|spider|slurp|preview|headless/i', $ua));
if ($p && isset($_GET['src']) && $_GET['src'] === 'qr' && !$es_robot) {
    try {
        $ahora = (new DateTime('now', new DateTimeZone('America/Mexico_City')))->format('Y-m-d H:i:s');
        $conexion->prepare("INSERT INTO escaneos_qr (producto_id, fecha_escaneo) VALUES (?, ?)")->execute([$p['id'], $ahora]);
    } catch (Exception $e) {
        // Si la tabla aún no existe, la página sigue funcionando
    }
}

if ($p) {
    $sin_iva = (float)$p['precio'];
    $iva = $sin_iva * 0.16;
    $con_iva = $sin_iva + $iva;
    $stock_n = (int)$p['stock'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TechPCs | Producto</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; background: #0f172a; min-height: 100vh; }
        header { background: #0b1220; color: white; text-align: center; padding: 18px 10px; font-size: 26px; font-weight: 800; letter-spacing: 0.5px; border-bottom: 3px solid #2563eb; }
        header span { color: #3b82f6; }
        .card { background: #ffffff; max-width: 420px; margin: 22px auto; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.45); }
        .card img { width: 100%; display: block; background: #f1f5f9; max-height: 380px; object-fit: contain; }
        .body { padding: 22px; }
        h1 { color: #1e293b; font-size: 24px; margin: 0 0 12px; }
        .stock { display: inline-block; padding: 6px 14px; border-radius: 20px; margin-bottom: 18px; font-size: 14px; font-weight: 700; background: #dcfce7; color: #15803d; }
        .stock.bajo { background: #fef3c7; color: #b45309; }
        .stock.agotado { background: #fee2e2; color: #b91c1c; }
        .fila { display: flex; justify-content: space-between; padding: 11px 0; border-bottom: 1px solid #e2e8f0; font-size: 17px; color: #475569; }
        .total { display: flex; justify-content: space-between; padding: 16px 0 6px; margin-top: 6px; font-size: 22px; color: #2563eb; font-weight: 800; }
        .btn { display: block; text-align: center; background: #2563eb; color: white; padding: 14px; border-radius: 10px; text-decoration: none; font-size: 17px; font-weight: 700; margin-top: 14px; }
        .btn:hover { background: #1d4ed8; }
        footer { text-align: center; color: #64748b; font-size: 13px; padding: 10px 0 25px; }
    </style>
</head>
<body>
<header>Tech<span>PCs</span></header>

<div class="card">
<?php if ($p): ?>
    <img src="obtener_imagen.php?id=<?php echo (int)$p['id']; ?>" alt="<?php echo htmlspecialchars($p['nombre']); ?>">
    <div class="body">
        <h1><?php echo htmlspecialchars($p['nombre']); ?></h1>

        <?php if ($stock_n <= 0): ?>
            <div class="stock agotado">Agotado</div>
        <?php elseif ($stock_n <= 5): ?>
            <div class="stock bajo">Stock disponible: <?php echo $stock_n; ?></div>
        <?php else: ?>
            <div class="stock">Stock disponible: <?php echo $stock_n; ?></div>
        <?php endif; ?>

        <div class="fila"><span>Precio sin IVA:</span><span>$<?php echo number_format($sin_iva, 2); ?></span></div>
        <div class="fila"><span>IVA (16%):</span><span>$<?php echo number_format($iva, 2); ?></span></div>
        <div class="total"><span>Precio con IVA:</span><span>$<?php echo number_format($con_iva, 2); ?></span></div>

        <a href="productos.php" class="btn">Ir a la tienda</a>
    </div>
<?php else: ?>
    <div class="body">
        <h1>Producto no encontrado</h1>
        <a href="productos.php" class="btn">Ir a la tienda</a>
    </div>
<?php endif; ?>
</div>

<footer>© <?php echo date('Y'); ?> TechPCs</footer>
</body>
</html>
