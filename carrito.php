<?php
session_start();
date_default_timezone_set('America/Mexico_City');

// Incluir la conexión centralizada (compatible con local y producción)
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
                // Usamos la variable $conexion definida en componentes/conexion.php
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

                        $html_ticket = '
                        <!DOCTYPE html>
                        <html lang="es">
                        <head>
                            <meta charset="UTF-8">
                            <style>
                                body { font-family: "Helvetica", "Arial", sans-serif; color: #333; margin: 0; padding: 15px; font-size: 12px; }
                                .header { text-align: center; border-bottom: 2px solid #1976d2; padding-bottom: 8px; margin-bottom: 15px; }
                                .header h2 { margin: 0; color: #1976d2; font-size: 18px; }
                                .info { margin-bottom: 15px; font-size: 11px; background: #f9f9f9; padding: 8px; border-radius: 4px; }
                                table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
                                th, td { padding: 8px; border-bottom: 1px solid #ddd; text-align: left; vertical-align: middle; }
                                th { background-color: #1976d2; color: white; font-size: 11px; }
                                .ticket-img { width: 40px; height: 40px; object-fit: cover; border-radius: 4px; border: 1px solid #ccc; }
                                .totals { text-align: right; font-size: 13px; margin-top: 10px; }
                                .totals div { margin-bottom: 4px; }
                                .total-final { font-weight: bold; color: #1976d2; font-size: 15px; border-top: 1px solid #333; padding-top: 5px; }
                                .footer { text-align: center; font-size: 10px; color: #777; margin-top: 25px; border-top: 1px dashed #ccc; padding-top: 8px; }
                            </style>
                        </head>
                        <body>
                            <div class="header">
                                <h2>PCERAS TECHNOLOGIES</h2>
                                <div>Comprobante de Compra</div>
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
                                        <th style="text-align: right;">Precio (Sin IVA)</th>
                                        <th style="text-align: right;">Subtotal (Sin IVA)</th>
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
                                <div>Subtotal (Sin IVA): <strong>$' . number_format($subtotal, 2) . '</strong></div>
                                <div>IVA (16%): <strong>$' . number_format($iva, 2) . '</strong></div>
                                <div class="total-final">TOTAL (Con IVA): $' . number_format($total_final, 2) . '</div>
                            </div>
                            <div class="footer">
                                ¡Gracias por tu compra en PCeras technologies!<br>Conserva este ticket digital.
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

                        $pdf_path = sys_get_temp_dir() . '/Ticket_PCeras_' . time() . '.pdf';
                        file_put_contents($pdf_path, $pdf_output);

                        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                        try {
                            $mail->isSMTP();
                            $mail->Host       = 'smtp.gmail.com';
                            $mail->SMTPAuth   = true;
                            $mail->Username   = 'johanraciel@gmail.com'; 
                            $mail->Password   = 'xztlzescicehqlfq'; 
                            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                            $mail->Port       = 587;

                            $mail->setFrom('johanraciel@gmail.com', 'PCeras technologies');
                            $mail->addAddress($correo_usuario, $usuario_actual);

                            $mail->isHTML(true);
                            $mail->Subject = 'Tu Ticket de Compra - PCeras technologies';
                            $mail->Body    = 'Hola <strong>' . htmlspecialchars($usuario_actual) . '</strong>,<br><br>Adjunto encontrarás el ticket oficial de tu compra con el detalle de tus productos.<br><br>¡Gracias por confiar en PCeras technologies!';
                            
                            $mail->addAttachment($pdf_path, 'Ticket_Compra_PCeras.pdf');
                            $mail->send();
                        } catch (Exception $e) {
                            $error_msg = "Error al enviar el correo: " . htmlspecialchars($mail->ErrorInfo);
                        }
                    }

                    $_SESSION['carrito'] = [];
                    $success_msg = "¡Ticket enviado con éxito a tu correo electrónico (" . htmlspecialchars($correo_usuario) . ")!";
                } else {
                    if (isset($conexion) && $conexion->inTransaction()) { $conexion->rollBack(); }
                    if (empty($error_msg)) {
                        $error_msg = "Error: Stock insuficiente para completar la compra.";
                    }
                }
            } catch (Exception $e) {
                if (isset($conexion) && $conexion->inTransaction()) { $conexion->rollBack(); }
                $error_msg = "Error en el servidor al procesar la compra: " . $e->getMessage();
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
<!-- El resto de tu estructura HTML del carrito se mantiene intacta -->
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Carrito de Compras - PCeras technologies</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; background-color: #f4f6f9; color: #333; padding: 40px 0; }
        .container { max-width: 950px; margin: 0 auto; background: #ffffff; padding: 25px 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border: 1px solid #e0e0e0; }
        h2 { color: #222; margin-top: 0; font-size: 1.4em; margin-bottom: 20px; }
        table.cart-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        table.cart-table th { background-color: #343a40; color: white; padding: 10px 12px; text-align: left; font-size: 0.9em; font-weight: bold; }
        table.cart-table td { padding: 12px; border-bottom: 1px solid #e9ecef; vertical-align: middle; font-size: 0.95em; }
        .prod-img { width: 55px; height: 55px; object-fit: cover; border-radius: 4px; border: 1px solid #ccc; }
        .input-cant { width: 50px; padding: 6px; text-align: center; border: 1px solid #ccc; border-radius: 4px; font-size: 0.95em; }
        .btn-action { padding: 7px 14px; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; color: white; font-size: 0.9em; text-decoration: none; display: inline-block; }
        .btn-update { background-color: #007bff; float: right; margin-bottom: 15px; }
        .btn-delete { background-color: #dc3545; padding: 5px 10px; font-size: 0.85em; }
        .btn-email { background-color: #28a745; font-size: 0.95em; padding: 8px 16px; }
        .btn-print { background-color: #007bff; font-size: 0.95em; padding: 8px 16px; text-decoration: none; display: inline-flex; align-items: center; }
        .btn-back { background-color: #6c757d; font-size: 0.9em; padding: 8px 14px; }
        .btn-empty { background-color: #dc3545; font-size: 0.9em; padding: 8px 14px; }
        .totals-section { text-align: right; margin-top: 10px; font-size: 0.95em; color: #444; line-height: 1.5; clear: both; }
        .user-action-bar { display: flex; justify-content: space-between; align-items: center; background: #ffffff; padding: 15px 20px; border: 1px solid #dcdcdc; border-radius: 6px; margin-top: 25px; }
        .alert-error { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin-bottom: 15px; font-weight: bold; font-size: 0.9em; }
        .alert-success { background: #d4edda; color: #155724; padding: 12px; border-radius: 6px; margin-bottom: 15px; font-weight: bold; text-align: center; font-size: 1em; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Tu Carrito de Compras</h2>
        <?php if (!empty($error_msg)): ?><div class="alert-error"><?php echo $error_msg; ?></div><?php endif; ?>
        <?php if (!empty($success_msg)): ?><div class="alert-success"><?php echo $success_msg; ?></div><?php endif; ?>

        <?php if (empty($_SESSION['carrito'])): ?>
            <div style="text-align: center; padding: 40px; color: #666;">
                <p>Tu carrito está vacío.</p>
                <a href="productos.php" class="btn-action btn-update" style="float: none; display: inline-block; margin-top: 10px;">Explorar Productos</a>
            </div>
        <?php else: ?>
            <form action="carrito.php" method="POST">
                <input type="hidden" name="action" value="actualizar">
                <table class="cart-table">
                    <thead>
                        <tr>
                            <th style="width: 80px;">Imagen</th>
                            <th>Producto</th>
                            <th style="width: 90px; text-align: center;">Cantidad</th>
                            <th style="text-align: right;">Precio Unitario (Sin IVA)</th>
                            <th style="text-align: right;">Subtotal (Sin IVA)</th>
                            <th style="width: 80px; text-align: center;">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($_SESSION['carrito'] as $id => $item): 
                            $subtotal_producto = $item['precio'] * $item['cantidad'];
                            $producto_id = isset($item['id']) ? $item['id'] : $id;
                            $img_src = "obtener_imagen.php?id=" . $producto_id;
                        ?>
                            <tr>
                                <td><img src="<?php echo $img_src; ?>" class="prod-img" alt="Imagen"></td>
                                <td><strong><?php echo htmlspecialchars($item['nombre']); ?></strong></td>
                                <td style="text-align: center;">
                                    <input type="number" name="cantidad[<?php echo $id; ?>]" value="<?php echo $item['cantidad']; ?>" min="1" class="input-cant">
                                </td>
                                <td style="text-align: right;">$<?php echo number_format($item['precio'], 2); ?></td>
                                <td style="text-align: right; font-weight: bold;">$<?php echo number_format($subtotal_producto, 2); ?></td>
                                <td style="text-align: center;">
                                    <button type="submit" formaction="carrito.php" name="action" value="eliminar" onclick="this.form.id_producto.value='<?php echo $id; ?>'" class="btn-action btn-delete">Eliminar</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <input type="hidden" name="id_producto" value="">
                <button type="submit" class="btn-action btn-update">Actualizar Cantidades</button>
            </form>

            <div class="totals-section">
                <div>Subtotal (Sin IVA): $<?php echo number_format($subtotal, 2); ?></div>
                <div>IVA (16%): $<?php echo number_format($iva, 2); ?></div>
                <div style="font-size: 1.15em; font-weight: bold; margin-top: 5px; color: #000;">Total (Precio con IVA): $<?php echo number_format($total_final, 2); ?></div>
            </div>

            <div class="user-action-bar">
                <div>
                    <strong>Usuario:</strong> 
                    <span style="color: #007bff; font-weight: bold;"><?php echo htmlspecialchars(isset($_SESSION['usuario']) ? $_SESSION['usuario'] : 'Invitado'); ?></span>
                </div>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <form action="carrito.php" method="POST" style="margin:0;">
                        <input type="hidden" name="action" value="enviar_correo">
                        <button type="submit" class="btn-action btn-email">Enviar a Correo</button>
                    </form>
                    <a href="generar_ticket.php" target="_blank" class="btn-action btn-print">Imprimir Ticket</a>
                </div>
            </div>

            <div style="display: flex; justify-content: space-between; margin-top: 20px;">
                <a href="productos.php" class="btn-action btn-back">← Seguir Comprando</a>
                <form action="carrito.php" method="POST" onsubmit="return confirm('¿Estás seguro de vaciar el carrito?');" style="margin:0;">
                    <input type="hidden" name="action" value="vaciar">
                    <button type="submit" class="btn-action btn-empty">Vaciar Carrito</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>