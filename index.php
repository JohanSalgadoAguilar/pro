<?php
session_start();
include 'componentes/conexion.php';

$mensaje = "";
$tipo = "error";

// Mensaje enviado desde otras páginas (ej. carrito: "Debes iniciar sesión...")
if (isset($_GET['msg']) && $_GET['msg'] !== '') {
    $mensaje = $_GET['msg'];
    $tipo = "info";
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $usuario = trim($_POST['usuario']);
    $password = trim($_POST['password']);
    $mensaje = "";

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
                        // Si venía del carrito, regresa ahí; si no, al catálogo
                        $destino = 'productos.php';
                        if (isset($_SESSION['redirect_after_login']) && $_SESSION['redirect_after_login'] === 'carrito.php') {
                            $destino = 'carrito.php';
                        }
                        unset($_SESSION['redirect_after_login']);
                        header("Location: " . $destino);
                        exit();
                    }
                } else {
                    // 2. REGISTRAR INTENTO FALLIDO (Contraseña incorrecta)
                    $stmtLog = $conexion->prepare("INSERT INTO bitacora_intentos (usuario_id, usuario_ingresado, resultado) VALUES (:uid, :u, 'fallido')");
                    $stmtLog->execute([':uid' => $user['id'], ':u' => $usuario]);

                    $mensaje = "Contraseña incorrecta.";
                    $tipo = "error";
                }
            } else {
                // 3. REGISTRAR INTENTO FALLIDO (El usuario no existe)
                $stmtLog = $conexion->prepare("INSERT INTO bitacora_intentos (usuario_id, usuario_ingresado, resultado) VALUES (NULL, :u, 'fallido')");
                $stmtLog->execute([':u' => $usuario]);

                $mensaje = "El usuario no existe.";
                $tipo = "error";
            }
        } catch (PDOException $e) {
            $mensaje = "Error en el sistema. Inténtalo de nuevo más tarde.";
            $tipo = "error";
        }
    } else {
        $mensaje = "Completa todos los campos.";
        $tipo = "aviso";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TechPCs | Iniciar sesión</title>
    <link rel="stylesheet" href="componentes/estilos.css?v=1">
</head>
<body class="auth-body">

<div class="auth-card">
    <h1 class="auth-logo">Tech<span>PCs</span></h1>
    <p class="auth-sub">Inicia sesión para continuar</p>

    <?php if ($mensaje !== ""): ?>
        <div class="alerta alerta-<?php echo $tipo; ?>"><?php echo htmlspecialchars($mensaje); ?></div>
    <?php endif; ?>

    <form action="index.php" method="POST">
        <div class="form-group">
            <label for="usuario">Usuario</label>
            <input type="text" id="usuario" name="usuario" placeholder="Tu nombre de usuario" required autofocus>
        </div>
        <div class="form-group">
            <label for="password">Contraseña</label>
            <input type="password" id="password" name="password" placeholder="••••••••" required>
        </div>
        <button type="submit" class="btn btn-azul btn-block">Entrar</button>
    </form>

    <div class="divisor">o</div>

    <!-- Botón para ver productos sin registrarse -->
    <a href="productos.php" class="btn btn-oscuro btn-block">Ver catálogo como invitado</a>

    <div class="auth-links">
        ¿No tienes cuenta? <a href="registro.php">Regístrate</a>
    </div>
</div>

</body>
</html>