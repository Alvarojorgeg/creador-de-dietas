<?php
require_once __DIR__ . '/../../includes/conexion.php';
requiere_rol('dietista', '../../');

$uid   = usuario_id();
$ok    = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $error = 'Sesión expirada.';
    } else {
        $accion = $_POST['accion'] ?? '';

        if ($accion === 'datos_cuenta') {
            $nombre = trim($_POST['nombre_completo'] ?? '');
            $email  = trim($_POST['email'] ?? '');
            if ($nombre === '' || $email === '') $error = 'Nombre y email son obligatorios.';
            else {
                $stmt = $conn->prepare("UPDATE usuarios SET nombre_completo=?, email=? WHERE id=?");
                $stmt->bind_param('ssi', $nombre, $email, $uid);
                if ($stmt->execute()) { $_SESSION['usuario_nombre'] = $nombre; $ok = 'Datos actualizados.'; }
                else $error = ($conn->errno === 1062) ? 'Ese email ya está en uso.' : 'Error al guardar.';
                $stmt->close();
            }
        }
        elseif ($accion === 'password') {
            $actual = (string)($_POST['password_actual'] ?? '');
            $nueva  = (string)($_POST['password_nueva']  ?? '');
            $rep    = (string)($_POST['password_rep']    ?? '');
            if ($actual === '' || $nueva === '' || $rep === '') $error = 'Rellena todos los campos.';
            elseif (strlen($nueva) < 4)                          $error = 'Mínimo 4 caracteres.';
            elseif ($nueva !== $rep)                             $error = 'Las contraseñas no coinciden.';
            else {
                $stmt = $conn->prepare("SELECT password FROM usuarios WHERE id=?");
                $stmt->bind_param('i', $uid); $stmt->execute();
                $h = $stmt->get_result()->fetch_assoc()['password'] ?? '';
                $stmt->close();
                if (!password_verify($actual, $h)) $error = 'Contraseña actual incorrecta.';
                else {
                    $newH = password_hash($nueva, PASSWORD_BCRYPT);
                    $stmt = $conn->prepare("UPDATE usuarios SET password=? WHERE id=?");
                    $stmt->bind_param('si', $newH, $uid); $stmt->execute(); $stmt->close();
                    $ok = 'Contraseña actualizada.';
                }
            }
        }
    }
}

// Datos cuenta
$stmt = $conn->prepare("SELECT nombre_completo, email, fecha_registro, ultima_actividad FROM usuarios WHERE id=?");
$stmt->bind_param('i', $uid); $stmt->execute();
$u = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Stats interesantes del dietista
$nClientes = (int)$conn->query("SELECT COUNT(*) c FROM usuarios WHERE id_dietista=$uid AND rol='cliente' AND activo=1")->fetch_assoc()['c'];

$stmt = $conn->prepare("SELECT COUNT(*) c FROM usuarios WHERE id_dietista=? AND rol='cliente' AND activo=1 AND ultima_actividad >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
$stmt->bind_param('i', $uid); $stmt->execute();
$nClientesActivos = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) c FROM dietas_base WHERE id_dietista=?");
$stmt->bind_param('i', $uid); $stmt->execute();
$nDietas = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) c FROM dietas_base WHERE id_dietista=? AND id_cliente IS NULL");
$stmt->bind_param('i', $uid); $stmt->execute();
$nPlantillas = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) c FROM consultas WHERE id_dietista=? AND fecha BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)");
$stmt->bind_param('i', $uid); $stmt->execute();
$nConsultas7d = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) c FROM checkins_semanales ck JOIN usuarios u ON u.id=ck.id_cliente WHERE u.id_dietista=? AND ck.fecha_registro >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
$stmt->bind_param('i', $uid); $stmt->execute();
$nCheckins7d = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

$base   = '../../';
$active = 'perfil';
$titulo = 'Mi perfil';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main class="page">

  <?php if ($ok):    ?><div class="alert alert-success" role="status"><?= e($ok) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"  role="alert"><?= e($error) ?></div><?php endif; ?>

  <!-- Cabecera -->
  <article class="card ficha-id">
    <div class="chats-avatar ficha-avatar">
      <?= e(mb_strtoupper(mb_substr($u['nombre_completo'], 0, 1, 'UTF-8'), 'UTF-8')) ?>
    </div>
    <div class="ficha-id-info">
      <h2 class="ficha-name"><?= e($u['nombre_completo']) ?></h2>
      <div class="ficha-email"><?= e($u['email']) ?></div>
      <div class="text-muted ficha-meta">
        Dietista desde <?= e(date('d/m/Y', strtotime($u['fecha_registro']))) ?>
      </div>
    </div>
  </article>

  <!-- Stats personales -->
  <section class="card">
    <h3 class="card-title">📊 Mi actividad</h3>
    <div class="prog-stats">
      <div class="prog-stat">
        <span class="prog-stat-num"><?= $nClientes ?></span>
        <span class="prog-stat-lbl">Clientes activos</span>
      </div>
      <div class="prog-stat">
        <span class="prog-stat-num is-down"><?= $nClientesActivos ?></span>
        <span class="prog-stat-lbl">Activos 7d</span>
      </div>
      <div class="prog-stat">
        <span class="prog-stat-num"><?= $nDietas ?></span>
        <span class="prog-stat-lbl">Dietas creadas</span>
      </div>
      <div class="prog-stat">
        <span class="prog-stat-num"><?= $nPlantillas ?></span>
        <span class="prog-stat-lbl">Plantillas</span>
      </div>
    </div>
    <div class="prog-stats" style="margin-top:var(--sp-3);">
      <div class="prog-stat">
        <span class="prog-stat-num"><?= $nConsultas7d ?></span>
        <span class="prog-stat-lbl">Consultas próx. 7d</span>
      </div>
      <div class="prog-stat">
        <span class="prog-stat-num"><?= $nCheckins7d ?></span>
        <span class="prog-stat-lbl">Check-ins esta semana</span>
      </div>
    </div>
  </section>

  <!-- Datos de cuenta -->
  <form method="post" class="card">
    <h2 class="card-title">👤 Datos de cuenta</h2>
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="accion" value="datos_cuenta">
    <label class="field">
      <span class="field-label">Nombre completo</span>
      <input class="field-input" type="text" name="nombre_completo" required value="<?= e($u['nombre_completo']) ?>">
    </label>
    <label class="field">
      <span class="field-label">Email</span>
      <input class="field-input" type="email" name="email" required value="<?= e($u['email']) ?>">
    </label>
    <button type="submit" class="btn btn-primary btn-block">Guardar datos</button>
  </form>

  <!-- Apariencia -->
  <article class="card">
    <h2 class="card-title">🎨 Apariencia</h2>
    <p class="text-soft">Tu elección se recuerda en este dispositivo.</p>
    <div class="theme-seg" role="group" aria-label="Tema de la interfaz">
      <button type="button" class="theme-seg-opt" data-theme="light">
        <span class="theme-seg-icon" aria-hidden="true">☀️</span>
        <span class="theme-seg-text">Claro</span>
      </button>
      <button type="button" class="theme-seg-opt" data-theme="dark">
        <span class="theme-seg-icon" aria-hidden="true">🌙</span>
        <span class="theme-seg-text">Oscuro</span>
      </button>
    </div>
  </article>

  <!-- Contraseña -->
  <form method="post" class="card">
    <h2 class="card-title">🔒 Cambiar contraseña</h2>
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="accion" value="password">
    <label class="field"><span class="field-label">Actual</span>
      <input class="field-input" type="password" name="password_actual" autocomplete="current-password" required></label>
    <label class="field"><span class="field-label">Nueva</span>
      <input class="field-input" type="password" name="password_nueva" autocomplete="new-password" required></label>
    <label class="field"><span class="field-label">Repite nueva</span>
      <input class="field-input" type="password" name="password_rep" autocomplete="new-password" required></label>
    <button type="submit" class="btn btn-primary btn-block">Cambiar contraseña</button>
  </form>

</main>

<script>
(function(){
  const opts = document.querySelectorAll('.theme-seg-opt');
  function pintar(){
    const t = window.AppTheme ? window.AppTheme.get() : 'light';
    opts.forEach(o => o.classList.toggle('is-active', o.dataset.theme === t));
  }
  opts.forEach(o => o.addEventListener('click', () => {
    if (!window.AppTheme) return;
    window.AppTheme.set(o.dataset.theme);
    pintar();
  }));
  pintar();
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
