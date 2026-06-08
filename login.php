<?php
require_once __DIR__ . '/includes/conexion.php';

if (esta_logueado()) {
    header('Location: ' . ruta_dashboard(rol_actual()));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $error = 'La sesión ha expirado. Vuelve a intentarlo.';
    } else {
        $identificador = trim($_POST['identificador'] ?? '');
        $pass          = (string)($_POST['password'] ?? '');

        if ($identificador === '' || $pass === '') {
            $error = 'Introduce usuario o correo y contraseña.';
        } else {
            // Acepta usuario O email: busca por cualquiera de los dos
            $stmt = $conn->prepare(
                'SELECT id, nombre_completo, usuario, email, password, rol, activo
                 FROM usuarios
                 WHERE usuario = ? OR email = ?
                 LIMIT 1'
            );
            $stmt->bind_param('ss', $identificador, $identificador);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($user && (int)$user['activo'] === 1 && password_verify($pass, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['usuario_id']     = (int)$user['id'];
                $_SESSION['usuario_nombre'] = $user['nombre_completo'];
                $_SESSION['usuario_user']   = $user['usuario'];
                $_SESSION['rol']            = $user['rol'];

                $upd = $conn->prepare('UPDATE usuarios SET ultimo_login = NOW(), ultima_actividad = NOW() WHERE id = ?');
                $uid = (int)$user['id'];
                $upd->bind_param('i', $uid);
                $upd->execute();
                $upd->close();

                header('Location: ' . ruta_dashboard($user['rol']));
                exit;
            }
            $error = 'Credenciales incorrectas.';
        }
    }
}

if (!$error && isset($_GET['e'])) {
    if ($_GET['e'] === 'permiso') $error = 'No tienes permiso para acceder a esa sección.';
    if ($_GET['e'] === 'sesion')  $error = 'Tu sesión ha caducado, inicia sesión de nuevo.';
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#2F9E73">
<title>DIETISTA · Iniciar sesión</title>
<link rel="stylesheet" href="css/style.css">
</head>
<body class="auth-body">
  <main class="auth-card" aria-labelledby="login-title">
    <header class="auth-header">
      <div class="auth-logo" aria-hidden="true">🥗</div>
      <h1 id="login-title" class="auth-title">DIETISTA</h1>
      <p class="auth-subtitle">Accede a tu cuenta</p>
    </header>

    <?php if ($error !== ''): ?>
      <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" class="auth-form" novalidate>
      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

      <label class="field">
        <span class="field-label">Usuario o correo</span>
        <input
          class="field-input"
          type="text"
          name="identificador"
          autocomplete="username"
          autocapitalize="none"
          spellcheck="false"
          required
          placeholder=""
          value="<?= e($_POST['identificador'] ?? '') ?>">
      </label>

      <label class="field">
        <span class="field-label">Contraseña</span>
        <input
          class="field-input"
          type="password"
          name="password"
          autocomplete="current-password"
          required>
      </label>

      <button type="submit" class="btn btn-primary btn-block">Entrar</button>
    </form>

    <footer class="auth-footer">
      <small>¿Problemas para acceder? Contacta con tu dietista.</small>
    </footer>
  </main>
</body>
</html>
