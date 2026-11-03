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
            header("Content-Type: image/jpeg"); 
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