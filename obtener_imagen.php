<?php
// Limpia cualquier salida previa del búfer
while (ob_get_level()) {
    ob_end_clean();
}

require 'componentes/conexion.php';

if (isset($_GET['id'])) {
    $id = intval($_GET['id']);
    
    try {
        $stmt = $conexion->prepare("SELECT imagen FROM productos WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $producto = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($producto && !empty($producto['imagen'])) {
            // Detecta el tipo real (jpeg, png, webp...) y usa jpeg si no se puede
            $mime = 'image/jpeg';
            if (class_exists('finfo')) {
                $detectado = (new finfo(FILEINFO_MIME_TYPE))->buffer($producto['imagen']);
                if ($detectado && strpos($detectado, 'image/') === 0) {
                    $mime = $detectado;
                }
            }
            header("Content-Type: " . $mime);
            echo $producto['imagen'];
            exit();
        }
    } catch (Exception $e) {
        // Excepción silenciosa
    }
}

// Imagen por defecto si no se encuentra
header("Content-Type: image/png");
if (file_exists("componentes/media/default.png")) {
    readfile("componentes/media/default.png");
}
exit();
?>