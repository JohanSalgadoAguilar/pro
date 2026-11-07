<?php
session_start();
include 'componentes/conexion.php';

$mensaje = "";
$tipo = "error";
$mensaje_html = false; // true solo para mensajes fijos que llevan un enlace

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $usuario = trim($_POST['usuario']);
    $correo = trim($_POST['correo']);
    $password = trim($_POST['password']);

    if (!empty($usuario) && !empty($correo) && !empty($password)) {
        try {
            // Verificar si el usuario o correo ya existen en la base de datos
            $stmt_check = $conexion->prepare("SELECT id FROM usuarios WHERE usuario = :usuario OR correo = :correo");
            $stmt_check->execute([':usuario' => $usuario, ':correo' => $correo]);
            
            if ($stmt_check->rowCount() > 0) {
                $mensaje = "El nombre de usuario o correo ya están registrados.";
                $tipo = "error";
            } else {
                // Asignar automáticamente el rol de usuario normal
                $rol = 'usuario';

                // Insertar el nuevo registro guardando la contraseña en texto plano
                $stmt = $conexion->prepare("INSERT INTO usuarios (usuario, correo, password, rol) VALUES (:usuario, :correo, :password, :rol)");
                if ($stmt->execute([':usuario' => $usuario, ':correo' => $correo, ':password' => $password, ':rol' => $rol])) {
                    $mensaje = "¡Registro exitoso! Ya puedes <a href='index.php'>iniciar sesión</a>.";
                    $mensaje_html = true;
                    $tipo = "ok";
                } else {
                    $mensaje = "Ocurrió un error al registrarse.";
                    $tipo = "error";
                }
            }
        } catch (PDOException $e) {
            $mensaje = "Error en el sistema. Inténtalo de nuevo más tarde.";
            $tipo = "error";
        }
    } else {
        $mensaje = "Por favor, completa todos los campos.";
        $tipo = "aviso";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TechPCs | Crear cuenta</title>
    <link rel="stylesheet" href="componentes/estilos.css?v=1">
</head>
<body class="auth-body">

<div class="auth-card">
    <h1 class="auth-logo">Tech<span>PCs</span></h1>
    <p class="auth-sub">Crea tu cuenta para comprar</p>

    <?php if ($mensaje !== ""): ?>
        <div class="alerta alerta-<?php echo $tipo; ?>"><?php echo $mensaje_html ? $mensaje : htmlspecialchars($mensaje); ?></div>
    <?php endif; ?>

    <form action="registro.php" method="POST">
        <div class="form-group">
            <label for="usuario">Usuario</label>
            <input type="text" id="usuario" name="usuario" placeholder="Elige un nombre de usuario" required>
        </div>
        <div class="form-group">
            <label for="correo">Correo electrónico</label>
            <input type="email" id="correo" name="correo" placeholder="correo@ejemplo.com" required>
        </div>
        <div class="form-group">
            <label for="password">Contraseña</label>
            <input type="password" id="password" name="password" placeholder="••••••••" required>
        </div>
        <button type="submit" class="btn btn-verde btn-block">Crear cuenta</button>
    </form>

    <div class="auth-links">
        ¿Ya tienes cuenta? <a href="index.php">Inicia sesión</a>
    </div>
</div>

</body>
</html>