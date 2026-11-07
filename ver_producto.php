<?php
// Página pública (sin sesión) a la que apunta el QR
include 'componentes/conexion.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $conexion->prepare("SELECT id, nombre, stock, precio FROM productos WHERE id = :id");
$stmt->execute([':id' => $id]);
$p = $stmt->fetch(PDO::FETCH_ASSOC);

if ($p) {
    $sin_iva = (float)$p['precio'];
    $iva = $sin_iva * 0.16;
    $con_iva = $sin_iva + $iva;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Producto - Dominic Toretto's Garage</title>
    <style>
        body { margin: 0; font-family: Roboto, Arial, sans-serif; background: #111; }
        header { background: #3a78d4; color: white; text-align: center; padding: 18px 10px; font-size: 24px; }
        .card { background: #fafafa; max-width: 420px; margin: 20px auto; border-radius: 14px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.4); }
        .card img { width: 100%; display: block; background: #e8e8e8; }
        .body { padding: 20px; }
        h1 { color: #3a78d4; font-size: 24px; margin: 0 0 12px; font-weight: 400; }
        .stock { display: inline-block; background: #e8f5e9; color: #2e7d32; padding: 6px 14px; border-radius: 20px; margin-bottom: 18px; }
        .fila { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #eee; font-size: 18px; color: #444; }
        .total { display: flex; justify-content: space-between; padding: 14px 0; border-top: 2px solid #ddd; margin-top: 6px; font-size: 22px; color: #3a78d4; font-weight: bold; }
        .btn { display: block; text-align: center; background: #4db6d0; color: white; padding: 14px; border-radius: 8px; text-decoration: none; font-size: 18px; margin-top: 10px; }
    </style>
</head>
<body>
<header>Dominic Toretto's Garage 🏁</header>

<div class="card">
<?php if ($p): ?>
    <img src="obtener_imagen.php?id=<?php echo $p['id']; ?>" alt="Producto">
    <div class="body">
        <h1><?php echo htmlspecialchars($p['nombre']); ?></h1>
        <div class="stock">Stock disponible: <?php echo htmlspecialchars($p['stock']); ?></div>

        <div class="fila"><span>Precio sin IVA:</span><span>$<?php echo number_format($sin_iva, 2); ?></span></div>
        <div class="fila"><span>IVA (16%):</span><span>$<?php echo number_format($iva, 2); ?></span></div>
        <div class="total"><span>Precio con IVA:</span><span>$<?php echo number_format($con_iva, 2); ?></span></div>

        <a href="productos.php" class="btn">Ir a la tienda</a>
    </div>
<?php else: ?>
    <div class="body"><h1>Producto no encontrado</h1><a href="productos.php" class="btn">Ir a la tienda</a></div>
<?php endif; ?>
</div>
</body>
</html>
