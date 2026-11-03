<?php
session_start();
include 'componentes/conexion.php';

// Verificar que sea administrador
if (!isset($_SESSION['usuario']) || $_SESSION['rol'] !== 'admin') {
    header("Location: index.php");
    exit();
}

$error = "";
$usuario_data = ['usuario' => '', 'correo' => '', 'rol' => 'usuario'];

// 1. Obtener el ID del usuario a editar mediante GET al cargar la página
if (isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $conexion->prepare("SELECT id, usuario, correo, rol FROM usuarios WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $usuario_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario_data) {
        header("Location: panel_admin.php");
        exit();
    }
}

// 2. Procesar el formulario cuando se envía por POST (Actualización)
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Asegurarse de recibir el ID oculto del formulario
    $id = $_POST['id'];
    $usuario = trim($_POST['usuario']);
    $correo = trim($_POST['correo']);
    $password = trim($_POST['password']);
    $rol = $_POST['rol'];

    if (!empty($usuario) && !empty($correo)) {
        try {
            // Verificar si se desea actualizar la contraseña o mantener la actual
            if (!empty($password)) {
                $stmt = $conexion->prepare("UPDATE usuarios SET usuario = :u, correo = :c, password = :p, rol = :r WHERE id = :id");
                $resultado = $stmt->execute([
                    ':u' => $usuario, 
                    ':c' => $correo, 
                    ':p' => $password, 
                    ':r' => $rol,
                    ':id' => $id
                ]);
            } else {
                // Si la contraseña está vacía, no la sobrescribimos
                $stmt = $conexion->prepare("UPDATE usuarios SET usuario = :u, correo = :c, rol = :r WHERE id = :id");
                $resultado = $stmt->execute([
                    ':u' => $usuario, 
                    ':c' => $correo, 
                    ':r' => $rol,
                    ':id' => $id
                ]);
            }

            if ($resultado) {
                header("Location: panel_admin.php");
                exit();
            } else {
                $error = "Error al actualizar el usuario.";
            }
        } catch (PDOException $e) {
            $error = "Error en el sistema: " . $e->getMessage();
        }
    } else {
        $error = "Por favor, completa los campos obligatorios.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Editar Usuario</title>
    <style>
        body { font-family: Arial; background: #f4f4f9; padding: 40px; display: flex; justify-content: center; }
        .box { background: white; padding: 20px; border-radius: 8px; width: 300px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        input, select { width: 100%; padding: 8px; margin-bottom: 10px; box-sizing: border-box; }
        button { width: 100%; padding: 10px; background: #ffc107; color: black; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; }
        .error { color: red; font-size: 14px; margin-bottom: 10px; }
    </style>
</head>
<body>
<div class="box">
    <h3>Editar Usuario</h3>
    
    <?php if (!empty($error)) { echo "<div class='error'>$error</div>"; } ?>

    <form method="POST">
        <!-- Campo oculto indispensable para conservar el ID del usuario que estamos editando -->
        <input type="hidden" name="id" value="<?php echo htmlspecialchars($usuario_data['id']); ?>">

        <label>Usuario:</label>
        <input type="text" name="usuario" value="<?php echo htmlspecialchars($usuario_data['usuario']); ?>" required>

        <label>Correo:</label>
        <input type="email" name="correo" value="<?php echo htmlspecialchars($usuario_data['correo']); ?>" required>

        <label>Nueva Contraseña (dejar en blanco para no cambiar):</label>
        <input type="password" name="password">

        <label>Rol:</label>
        <select name="rol">
            <option value="usuario" <?php if ($usuario_data['rol'] === 'usuario') echo 'selected'; ?>>Usuario</option>
            <option value="admin" <?php if ($usuario_data['rol'] === 'admin') echo 'selected'; ?>>Admin</option>
        </select>

        <button type="submit">Actualizar Usuario</button>
    </form>
    <br><a href="panel_admin.php">Volver</a>
</div>
</body>
</html>