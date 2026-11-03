<?php
session_start();
// Conexión a tu base de datos
require 'componentes/conexion.php';

// Cargar PHPMailer desde Composer
require 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!isset($_SESSION['carrito']) || count($_SESSION['carrito']) == 0) {
    echo "El carrito está vacío.";
    exit();
}

// 1. OBTENER EL CORREO DEL USUARIO LOGUEADO DESDE LA BASE DE DATOS
$correo_usuario = "";
if (isset($_SESSION['usuario'])) {
    try {
        $stmt = $conexion->prepare("SELECT correo FROM usuarios WHERE usuario = ?");
        $stmt->execute([$_SESSION['usuario']]);
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($resultado && !empty($resultado['correo'])) {
            $correo_usuario = $resultado['correo'];
        }
    } catch (PDOException $e) {
        // Error silencioso de base de datos
    }
}

$accion = isset($_POST['accion_pdf']) ? $_POST['accion_pdf'] : 'imprimir';
$correo_enviado = false;

// 2. ENVIAR EL TICKET AL CORREO OBTENIDO DE LA BASE DE DATOS USANDO SMTP SEGURO
if ($accion == 'enviar_correo' && !empty($correo_usuario) && filter_var($correo_usuario, FILTER_VALIDATE_EMAIL)) {
    
    $mail = new PHPMailer(true);

    try {
        // Configuración del servidor de correo
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'tu_correo_real@gmail.com';         // <--- Tu correo real de envío
        $mail->Password   = 'tu_contrasena_de_aplicacion';      // <--- Tu contraseña de aplicación de Gmail
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Remitente y Destinatario (Aquí usa la variable $correo_usuario extraída de tu BD)
        $mail->setFrom('tu_correo_real@gmail.com', 'Muebles El Baños');
        $mail->addAddress($correo_usuario, $_SESSION['usuario']);

        // Construir el diseño del ticket en HTML
        $mensaje_html = '<html><body style="font-family: Arial, sans-serif; color: #333;">';
        $mensaje_html .= '<h2 style="color: #28a745;">Comprobante de Venta - Muebles El Baños</h2>';
        $mensaje_html .= '<p><b>Cliente:</b> ' . htmlspecialchars($_SESSION['usuario']) . '</p>';
        $mensaje_html .= '<p><b>Fecha:</b> ' . date('d/m/Y H:i:s') . '</p>';
        $mensaje_html .= '<table border="1" cellpadding="8" cellspacing="0" style="border-collapse: collapse; width: 100%; margin-top: 10px;">';
        $mensaje_html .= '<tr style="background: #343a40; color: white;"><th>Producto</th><th>Cantidad</th><th>Precio Unitario</th><th>Subtotal</th></tr>';
        
        $subtotal_general = 0;
        foreach ($_SESSION['carrito'] as $item) {
            $precio = $item['precio'];
            $cantidad = $item['cantidad'];
            $subsub = $precio * $cantidad;
            $subtotal_general += $subsub;

            $mensaje_html .= '<tr>';
            $mensaje_html .= '<td>' . htmlspecialchars($item['nombre']) . '</td>';
            $mensaje_html .= '<td align="center">' . $cantidad . '</td>';
            $mensaje_html .= '<td align="right">$' . number_format($precio, 2) . '</td>';
            $mensaje_html .= '<td align="right">$' . number_format($subsub, 2) . '</td>';
            $mensaje_html .= '</tr>';
        }
        
        $iva = $subtotal_general * 0.16;
        $total = $subtotal_general + $iva;

        $mensaje_html .= '</table>';
        $mensaje_html .= '<br><p align="right"><b>Subtotal:</b> $' . number_format($subtotal_general, 2) . '</p>';
        $mensaje_html .= '<p align="right"><b>IVA (16%):</b> $' . number_format($iva, 2) . '</p>';
        $mensaje_html .= '<p align="right" style="font-size: 16px; color: #28a745;"><b>TOTAL:</b> $' . number_format($total, 2) . '</p>';
        $mensaje_html .= '<p style="margin-top: 20px; text-align: center;">¡Gracias por su compra en Muebles El Baños!</p>';
        $mensaje_html .= '</body></html>';

        $mail->isHTML(true);
        $mail->Subject = 'Tu Ticket de Compra - Muebles El Baños';
        $mail->Body    = $mensaje_html;

        $mail->send();
        $correo_enviado = true;
    } catch (Exception $e) {
        $correo_enviado = false;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ticket de Compra</title>
    <style>
        body {
            font-family: 'Courier New', Courier, monospace;
            font-size: 13px;
            color: #000;
            background: #fff;
            margin: 0;
            padding: 20px;
        }
        .ticket {
            max-width: 380px;
            margin: 0 auto;
            background: #fff;
            padding: 15px;
            border: 1px dashed #ccc;
        }
        h2, p {
            text-align: center;
            margin: 5px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            margin-bottom: 15px;
        }
        th, td {
            padding: 6px 4px;
            text-align: left;
            vertical-align: middle;
            font-size: 12px;
        }
        th {
            border-bottom: 1px dashed #000;
        }
        .text-right {
            text-align: right;
        }
        .img-ticket {
            width: 35px;
            height: 35px;
            object-fit: cover;
            border-radius: 3px;
            border: 1px solid #ddd;
            display: block;
        }
        .totales {
            border-top: 1px dashed #000;
            padding-top: 10px;
            margin-top: 10px;
        }
        .alerta-correo {
            background: #d4edda;
            color: #155724;
            padding: 8px;
            font-size: 12px;
            text-align: center;
            margin-bottom: 15px;
            border-radius: 4px;
            border: 1px solid #c3e6cb;
        }
        .btn-imprimir {
            display: block;
            width: 100%;
            background: #28a745;
            color: white;
            border: none;
            padding: 10px;
            font-size: 15px;
            cursor: pointer;
            margin-top: 20px;
            border-radius: 4px;
        }
        .btn-imprimir:hover {
            background: #218838;
        }
        @media print {
            .btn-imprimir, .alerta-correo {
                display: none;
            }
            body {
                padding: 0;
            }
            .ticket {
                border: none;
            }
        }
    </style>
</head>
<body <?php echo ($accion == 'imprimir') ? 'onload="window.print();"' : ''; ?>>

<div class="ticket">
    <h2>MUEBLES EL BAÑOS</h2>
    <p>Comprobante de Venta</p>
    <p style="font-size: 11px; color: #555;"><?php echo date('d/m/Y H:i:s'); ?></p>
    
    <?php if ($correo_enviado): ?>
        <div class="alerta-correo">¡Ticket enviado con éxito a <?php echo htmlspecialchars($correo_usuario); ?>!</div>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th style="width: 40px;">Img</th>
                <th>Prod. / Cant.</th>
                <th class="text-right">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $subtotal_general = 0;
            foreach ($_SESSION['carrito'] as $item): 
                $precio = $item['precio'];
                $cantidad = $item['cantidad'];
                $subtotal_producto = $precio * $cantidad;
                $subtotal_general += $subtotal_producto;
            ?>
            <tr>
                <td>
                    <img src="obtener_imagen.php?id=<?php echo $item['id']; ?>" alt="Img" class="img-ticket">
                </td>
                <td>
                    <?php echo htmlspecialchars($item['nombre']); ?><br>
                    <small><?php echo $cantidad; ?> x $<?php echo number_format($precio, 2); ?></small>
                </td>
                <td class="text-right">
                    $<?php echo number_format($subtotal_producto, 2); ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php 
        $iva_general = $subtotal_general * 0.16;
        $total_con_iva = $subtotal_general + $iva_general;
    ?>

    <div class="totales">
        <div><span>Subtotal:</span> <span>$<?php echo number_format($subtotal_general, 2); ?></span></div>
        <div><span>IVA (16%):</span> <span>$<?php echo number_format($iva_general, 2); ?></span></div>
        <div style="font-weight: bold; font-size: 14px; margin-top: 5px;">
            <span>TOTAL:</span> <span>$<?php echo number_format($total_con_iva, 2); ?></span>
        </div>
    </div>

    <p style="margin-top: 20px; font-size: 11px;">¡Gracias por su compra, <?php echo htmlspecialchars($_SESSION['usuario'] ?? 'Cliente'); ?>!</p>

    <button class="btn-imprimir" onclick="window.print()">Imprimir / Guardar PDF</button>
</div>

</body>
</html>