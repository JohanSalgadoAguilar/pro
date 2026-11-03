<?php
session_start();
include 'componentes/conexion.php';

$mensaje = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $usuario = trim($_POST['usuario']);
    $password = trim($_POST['password']);

    if (!empty($usuario) && !empty($password)) {
        try {
            $stmt = $conexion->prepare("SELECT id, usuario, password, rol FROM usuarios WHERE usuario = :usuario");
            $stmt->bindParam(':usuario', $usuario, PDO::PARAM_STR);
            $stmt->execute();
            
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                // Comparación directa en texto plano
                if ($password === $user['password']) {
                    // 1. REGISTRAR INTENTO EXITOSO EN LA BITÁCORA
                    $stmtLog = $conexion->prepare("INSERT INTO bitacora_intentos (usuario_id, usuario_ingresado, resultado) VALUES (:uid, :u, 'exitoso')");
                    $stmtLog->execute([':uid' => $user['id'], ':u' => $usuario]);

                    $_SESSION['id'] = $user['id'];
                    $_SESSION['usuario'] = $user['usuario'];
                    $_SESSION['rol'] = $user['rol'];
                    
                    if ($user['rol'] === 'admin') {
                        header("Location: panel_admin.php");
                        exit();
                    } else {
                        // Redirigir al usuario normal al catálogo de productos
                        header("Location: productos.php");
                        exit();
                    }
                } else {
                    // 2. REGISTRAR INTENTO FALLIDO (Contraseña incorrecta)
                    $stmtLog = $conexion->prepare("INSERT INTO bitacora_intentos (usuario_id, usuario_ingresado, resultado) VALUES (:uid, :u, 'fallido')");
                    $stmtLog->execute([':uid' => $user['id'], ':u' => $usuario]);

                    $mensaje = "<p style='color: red;'>Contraseña incorrecta.</p>";
                }
            } else {
                // 3. REGISTRAR INTENTO FALLIDO (El usuario no existe)
                $stmtLog = $conexion->prepare("INSERT INTO bitacora_intentos (usuario_id, usuario_ingresado, resultado) VALUES (NULL, :u, 'fallido')");
                $stmtLog->execute([':u' => $usuario]);

                $mensaje = "<p style='color: red;'>El usuario no existe.</p>";
            }
        } catch (PDOException $e) {
            $mensaje = "<p style='color: red;'>Error en el sistema: " . $e->getMessage() . "</p>";
        }
    } else {
        $mensaje = "<p style='color: orange;'>Completa todos los campos.</p>";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Inicio de Sesión</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #f4f4f9; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .login-card { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); width: 320px; }
        .login-card h2 { text-align: center; margin-bottom: 20px; color: #333; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; color: #666; }
        .form-group input { width: 100%; padding: 8px; box-sizing: border-box; border: 1px solid #ccc; border-radius: 4px; }
        .btn { width: 100%; padding: 10px; background: #007bff; border: none; color: white; border-radius: 4px; cursor: pointer; font-size: 16px; margin-bottom: 10px; }
        .btn:hover { background: #0056b3; }
        .btn-invitado { width: 100%; padding: 10px; background: #6c757d; border: none; color: white; border-radius: 4px; cursor: pointer; font-size: 16px; text-decoration: none; display: inline-block; text-align: center; box-sizing: border-box; }
        .btn-invitado:hover { background: #5a6268; }
        .nav-links { text-align: center; margin-top: 15px; font-size: 14px; }
        .nav-links a { color: #007bff; text-decoration: none; }
        .nav-links a:hover { text-decoration: underline; }
    </style>
</head>
<body>

<div class="login-card">
    <h2>Login Principal</h2>
    <?php echo $mensaje; ?>
    <form action="index.php" method="POST">
        <div class="form-group">
            <label for="usuario">Usuario:</label>
            <input type="text" id="usuario" name="usuario" required>
        </div>
        <div class="form-group">
            <label for="password">Contraseña:</label>
            <input type="password" id="password" name="password" required>
        </div>
        <button type="submit" class="btn">Entrar</button>
    </form>
    
    <!-- Botón para ver productos sin registrarse -->
    <a href="productos.php" class="btn-invitado">Ver Productos como Invitado</a>

    <div class="nav-links">
        <p>¿No tienes cuenta? <a href="registro.php">Regístrate</a></p>
    </div>
</div>

</body>
</html>