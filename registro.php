<?php
session_start();
include 'componentes/conexion.php';

$mensaje = "";

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
                $mensaje = "<p style='color: red;'>El nombre de usuario o correo ya están registrados.</p>";
            } else {
                // Asignar automáticamente el rol de usuario normal
                $rol = 'usuario';

                // Insertar el nuevo registro guardando la contraseña en texto plano
                $stmt = $conexion->prepare("INSERT INTO usuarios (usuario, correo, password, rol) VALUES (:usuario, :correo, :password, :rol)");
                if ($stmt->execute([':usuario' => $usuario, ':correo' => $correo, ':password' => $password, ':rol' => $rol])) {
                    $mensaje = "<p style='color: green;'>¡Registro exitoso! Ya puedes <a href='index.php'>iniciar sesión</a>.</p>";
                } else {
                    $mensaje = "<p style='color: red;'>Ocurrió un error al registrarse.</p>";
                }
            }
        } catch (PDOException $e) {
            $mensaje = "<p style='color: red;'>Error en el sistema: " . $e->getMessage() . "</p>";
        }
    } else {
        $mensaje = "<p style='color: orange;'>Por favor, completa todos los campos.</p>";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Registro de Usuario</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #f4f4f9; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .register-card { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); width: 320px; }
        .register-card h2 { text-align: center; margin-bottom: 20px; color: #333; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; color: #666; }
        .form-group input { width: 100%; padding: 8px; box-sizing: border-box; border: 1px solid #ccc; border-radius: 4px; }
        .btn { width: 100%; padding: 10px; background: #28a745; border: none; color: white; border-radius: 4px; cursor: pointer; font-size: 16px; }
        .btn:hover { background: #218838; }
        .nav-links { text-align: center; margin-top: 15px; font-size: 14px; }
        .nav-links a { color: #007bff; text-decoration: none; }
        .nav-links a:hover { text-decoration: underline; }
    </style>
</head>
<body>

<div class="register-card">
    <h2>Registro</h2>
    <?php echo $mensaje; ?>
    <form action="registro.php" method="POST">
        <div class="form-group">
            <label for="usuario">Usuario:</label>
            <input type="text" id="usuario" name="usuario" required>
        </div>
        <div class="form-group">
            <label for="correo">Correo Electrónico:</label>
            <input type="email" id="correo" name="correo" required>
        </div>
        <div class="form-group">
            <label for="password">Contraseña:</label>
            <input type="password" id="password" name="password" required>
        </div>
        <button type="submit" class="btn">Registrarse</button>
    </form>
    
    <div class="nav-links">
        <p>¿Ya tienes cuenta? <a href="index.php">Inicia sesión</a></p>
    </div>
</div>

</body>
</html>