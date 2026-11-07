<?php
session_start();
date_default_timezone_set('America/Mexico_City');

// Conexión centralizada
require 'componentes/conexion.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$is_logged_in = isset($_SESSION['usuario']) && !empty($_SESSION['usuario']);

if (!isset($_SESSION['carrito'])) {
    $_SESSION['carrito'] = [];
}

$error_msg = "";
$success_msg = "";

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $accion = $_POST['action'];

    if ($accion === 'actualizar' && isset($_POST['cantidad'])) {
        foreach ($_POST['cantidad'] as $id => $cant) {
            $cant = intval($cant);
            if ($cant > 0 && isset($_SESSION['carrito'][$id])) {
                $_SESSION['carrito'][$id]['cantidad'] = $cant;
            }
        }
        header("Location: carrito.php");
        exit;
    }

    if ($accion === 'eliminar' && isset($_POST['id_producto'])) {
        $id_prod = $_POST['id_producto'];
        unset($_SESSION['carrito'][$id_prod]);
        header("Location: carrito.php");
        exit;
    }

    if ($accion === 'vaciar') {
        $_SESSION['carrito'] = [];
        header("Location: carrito.php");
        exit;
    }

    if ($accion === 'enviar_correo') {
        if (!$is_logged_in) {
            $_SESSION['redirect_after_login'] = 'carrito.php';
            header("Location: index.php?msg=" . urlencode("Debes iniciar sesión para completar tu compra."));
            exit;
        }

        if (!empty($_SESSION['carrito'])) {
            try {
                $conexion->beginTransaction();
                $stmt_update = $conexion->prepare("UPDATE productos SET stock = stock - ? WHERE id = ? AND stock >= ?");
                
                $compra_exitosa = true;
                foreach ($_SESSION['carrito'] as $id => $item) {
                    $producto_id = isset($item['id']) ? $item['id'] : $id;
                    
                    if (!is_numeric($producto_id)) {
                        $compra_exitosa = false;
                        $error_msg = "Error: El identificador del producto no es válido.";
                        break;
                    }

                    $stmt_update->execute([$item['cantidad'], $producto_id, $item['cantidad']]);
                    if ($stmt_update->rowCount() === 0) {
                        $compra_exitosa = false;
                        break;
                    }
                }

                if ($compra_exitosa) {
                    $conexion->commit();
                    $correo_ok = false;
                    
                    $subtotal = array_sum(array_map(fn($p) => $p['precio'] * $p['cantidad'], $_SESSION['carrito']));
                    $iva = $subtotal * 0.16;
                    $total_final = $subtotal + $iva;

                    $usuario_actual = $_SESSION['usuario'];
                    $fecha_actual = date('Y-m-d');
                    $hora_actual = date('H:i:s');
                    
                    $stmt_correo = $conexion->prepare("SELECT correo FROM usuarios WHERE usuario = ?");
                    $stmt_correo->execute([$usuario_actual]);
                    $user_info = $stmt_correo->fetch(PDO::FETCH_ASSOC);
                    $correo_usuario = $user_info ? $user_info['correo'] : '';

                    if (!empty($correo_usuario) && file_exists('vendor/autoload.php')) {
                        require 'vendor/autoload.php';
                        $cfg = require 'componentes/correo_config.php';

                        $html_ticket = '
                        <!DOCTYPE html>
                        <html lang="es">
                        <head>
                            <meta charset="UTF-8">
                            <style>
                                body { font-family: "Helvetica", "Arial", sans-serif; color: #1e293b; margin: 0; padding: 15px; font-size: 12px; }
                                .header { text-align: center; border-bottom: 3px solid #2563eb; padding-bottom: 10px; margin-bottom: 15px; }
                                .header h2 { margin: 0; color: #0f172a; font-size: 22px; letter-spacing: 1px; }
                                .header h2 span { color: #2563eb; }
                                .info { margin-bottom: 15px; font-size: 11px; background: #f1f5f9; padding: 10px; border-radius: 4px; }
                                table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
                                th, td { padding: 8px; border-bottom: 1px solid #e2e8f0; text-align: left; vertical-align: middle; }
                                th { background-color: #0f172a; color: white; font-size: 11px; }
                                .ticket-img { width: 40px; height: 40px; object-fit: cover; border-radius: 4px; border: 1px solid #cbd5e1; }
                                .totals { text-align: right; font-size: 13px; margin-top: 10px; }
                                .totals div { margin-bottom: 4px; }
                                .total-final { font-weight: bold; color: #2563eb; font-size: 15px; border-top: 1px solid #0f172a; padding-top: 5px; }
                                .footer { text-align: center; font-size: 10px; color: #64748b; margin-top: 25px; border-top: 1px dashed #cbd5e1; padding-top: 8px; }
                            </style>
                        </head>
                        <body>
                            <div class="header">
                                <h2>TECH<span>PCS</span></h2>
                                <div>Comprobante de compra</div>
                            </div>
                            <div class="info">
                                <strong>Fecha:</strong> ' . $fecha_actual . ' | <strong>Hora:</strong> ' . $hora_actual . '<br>
                                <strong>Cliente:</strong> ' . htmlspecialchars($usuario_actual) . '<br>
                                <strong>Correo:</strong> ' . htmlspecialchars($correo_usuario) . '
                            </div>
                            <table>
                                <thead>
                                    <tr>
                                        <th style="width: 50px;">Img</th>
                                        <th>Producto</th>
                                        <th style="text-align: center;">Cant</th>
                                        <th style="text-align: right;">Precio (sin IVA)</th>
                                        <th style="text-align: right;">Subtotal (sin IVA)</th>
                                    </tr>
                                </thead>
                                <tbody>';

                        $stmt_img = $conexion->prepare("SELECT imagen FROM productos WHERE id = ?");

                        foreach ($_SESSION['carrito'] as $id => $item) {
                            $sub_p = $item['precio'] * $item['cantidad'];
                            $prod_id = isset($item['id']) ? $item['id'] : $id;
                            
                            $img_tag = '';
                            try {
                                $stmt_img->execute([$prod_id]);
                                $prod_data = $stmt_img->fetch(PDO::FETCH_ASSOC);
                                if ($prod_data && !empty($prod_data['imagen'])) {
                                    $imgData = $prod_data['imagen'];
                                    $is_webp = (substr($imgData, 0, 4) === 'RIFF' && substr($imgData, 8, 4) === 'WEBP');
                                    
                                    if (!$is_webp) {
                                        $base64_img = base64_encode($imgData);
                                        $img_tag = '<img src="data:image/jpeg;base64,' . $base64_img . '" class="ticket-img" alt="Img">';
                                    }
                                }
                            } catch (Exception $e) {
                                // Excepción silenciosa
                            }

                            $html_ticket .= '
                                    <tr>
                                        <td>' . $img_tag . '</td>
                                        <td><strong>' . htmlspecialchars($item['nombre']) . '</strong></td>
                                        <td style="text-align: center;">' . $item['cantidad'] . '</td>
                                        <td style="text-align: right;">$' . number_format($item['precio'], 2) . '</td>
                                        <td style="text-align: right; font-weight: bold;">$' . number_format($sub_p, 2) . '</td>
                                    </tr>';
                        }

                        $html_ticket .= '
                                </tbody>
                            </table>
                            <div class="totals">
                                <div>Subtotal (sin IVA): <strong>$' . number_format($subtotal, 2) . '</strong></div>
                                <div>IVA (16%): <strong>$' . number_format($iva, 2) . '</strong></div>
                                <div class="total-final">TOTAL (con IVA): $' . number_format($total_final, 2) . '</div>
                            </div>
                            <div class="footer">
                                Gracias por tu compra en TechPCs.<br>Conserva este ticket digital.
                            </div>
                        </body>
                        </html>';

                        $options = new Options();
                        $options->set('isRemoteEnabled', true);
                        $options->set('isHtml5ParserEnabled', true);
                        $dompdf = new Dompdf($options);
                        $dompdf->loadHtml($html_ticket);
                        $dompdf->setPaper('letter', 'portrait');
                        $dompdf->render();
                        $pdf_output = $dompdf->output();

                        $pdf_path = sys_get_temp_dir() . '/Ticket_TechPCs_' . time() . '.pdf';
                        file_put_contents($pdf_path, $pdf_output);

                        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                        try {
                            $mail->isSMTP();
                            $mail->Host       = 'smtp.gmail.com';
                            $mail->SMTPAuth   = true;
                            $mail->Username   = $cfg['usuario'];
                            $mail->Password   = $cfg['clave'];
                            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                            $mail->Port       = 587;
                            $mail->CharSet    = 'UTF-8';

                            $mail->setFrom($cfg['usuario'], $cfg['remitente']);
                            $mail->addAddress($correo_usuario, $usuario_actual);

                            $mail->isHTML(true);
                            $mail->Subject = 'Tu ticket de compra - TechPCs';
                            $mail->Body    = 'Hola <strong>' . htmlspecialchars($usuario_actual) . '</strong>,<br><br>Adjunto encontrarás el ticket oficial de tu compra con el detalle de tus productos.<br><br>Gracias por confiar en TechPCs.';
                            
                            $mail->addAttachment($pdf_path, 'Ticket_Compra_TechPCs.pdf');
                            $mail->send();
                            $correo_ok = true;
                        } catch (Exception $e) {
                            $error_msg = "La compra se registró, pero no se pudo enviar el correo: " . htmlspecialchars($mail->ErrorInfo);
                        }

                        if (file_exists($pdf_path)) { @unlink($pdf_path); }
                    }

                    $_SESSION['carrito'] = [];
                    $success_msg = $correo_ok
                        ? "¡Compra realizada con éxito! Enviamos tu ticket a " . htmlspecialchars($correo_usuario) . "."
                        : "¡Compra realizada con éxito!";
                } else {
                    if (isset($conexion) && $conexion->inTransaction()) { $conexion->rollBack(); }
                    if (empty($error_msg)) {
                        $error_msg = "Stock insuficiente para completar la compra.";
                    }
                }
            } catch (Exception $e) {
                if (isset($conexion) && $conexion->inTransaction()) { $conexion->rollBack(); }
                $error_msg = "Error en el servidor al procesar la compra. Inténtalo de nuevo.";
            }
        }
    }
}

$subtotal = 0;
foreach ($_SESSION['carrito'] as $item) {
    $subtotal += $item['precio'] * $item['cantidad'];
}
$iva = $subtotal * 0.16;
$total_final = $subtotal + $iva;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TechPCs | Carrito de compras</title>
    <link rel="stylesheet" href="componentes/estilos.css?v=1">
</head>
<body>

<nav class="navbar">
    <a href="productos.php" class="marca">Tech<span>PCs</span></a>
    <div class="nav-der">
        <?php if ($is_logged_in): ?>
            <span class="rol">Usuario: <?php echo htmlspecialchars($_SESSION['usuario']); ?></span>
        <?php endif; ?>
        <a href="productos.php" class="btn btn-gris">← Seguir comprando</a>
    </div>
</nav>

<div class="container">
    <?php if (!empty($error_msg)): ?><div class="alerta alerta-error"><?php echo $error_msg; ?></div><?php endif; ?>
    <?php if (!empty($success_msg)): ?><div class="alerta alerta-ok"><?php echo $success_msg; ?></div><?php endif; ?>

    <div class="panel">
        <div class="panel-top">
            <div>
                <h1>Tu carrito de compras</h1>
                <p><?php echo count($_SESSION['carrito']); ?> producto(s) en el carrito</p>
            </div>
        </div>

        <?php if (empty($_SESSION['carrito'])): ?>
            <div class="panel-body text-center" style="padding: 50px 20px; color: var(--suave);">
                <p style="font-size: 18px; margin-top: 0;">Tu carrito está vacío.</p>
                <a href="productos.php" class="btn btn-azul">Explorar productos</a>
            </div>
        <?php else: ?>
            <form action="carrito.php" method="POST">
                <input type="hidden" name="action" value="actualizar">
                <div class="tabla-wrap">
                    <table class="tabla">
                        <thead>
                            <tr>
                                <th style="width: 90px;">Imagen</th>
                                <th>Producto</th>
                                <th class="text-center" style="width: 110px;">Cantidad</th>
                                <th class="text-right">Precio unitario</th>
                                <th class="text-right">Subtotal</th>
                                <th class="text-center" style="width: 100px;">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($_SESSION['carrito'] as $id => $item): 
                                $subtotal_producto = $item['precio'] * $item['cantidad'];
                                $producto_id = isset($item['id']) ? $item['id'] : $id;
                                $img_src = "obtener_imagen.php?id=" . (int)$producto_id;
                            ?>
                                <tr>
                                    <td><img src="<?php echo $img_src; ?>" class="img-prod" alt="Imagen"></td>
                                    <td><strong><?php echo htmlspecialchars($item['nombre']); ?></strong></td>
                                    <td class="text-center">
                                        <input type="number" name="cantidad[<?php echo $id; ?>]" value="<?php echo (int)$item['cantidad']; ?>" min="1" class="input-cant">
                                    </td>
                                    <td class="text-right">$<?php echo number_format($item['precio'], 2); ?></td>
                                    <td class="text-right"><strong>$<?php echo number_format($subtotal_producto, 2); ?></strong></td>
                                    <td class="text-center">
                                        <button type="submit" formaction="carrito.php" name="action" value="eliminar" onclick="this.form.id_producto.value='<?php echo $id; ?>'" class="btn btn-rojo btn-sm">Quitar</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <input type="hidden" name="id_producto" value="">
                <div style="padding: 16px 24px; text-align: right;">
                    <button type="submit" class="btn btn-azul">Actualizar cantidades</button>
                </div>
            </form>

            <div class="resumen">
                <div><span>Subtotal (sin IVA)</span><span>$<?php echo number_format($subtotal, 2); ?></span></div>
                <div><span>IVA (16%)</span><span>$<?php echo number_format($iva, 2); ?></span></div>
                <div class="total"><span>Total (con IVA)</span><span>$<?php echo number_format($total_final, 2); ?></span></div>
            </div>

            <div class="barra-acciones">
                <form action="carrito.php" method="POST" onsubmit="return confirm('¿Estás seguro de vaciar el carrito?');" style="margin:0;">
                    <input type="hidden" name="action" value="vaciar">
                    <button type="submit" class="btn btn-rojo">Vaciar carrito</button>
                </form>
                <div class="acciones">
                    <a href="generar_ticket.php" target="_blank" class="btn btn-azul">Imprimir ticket</a>
                    <form action="carrito.php" method="POST" style="margin:0;">
                        <input type="hidden" name="action" value="enviar_correo">
                        <button type="submit" class="btn btn-verde">Finalizar compra y enviar ticket</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>