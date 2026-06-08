<?php
require_once __DIR__ . '/../../includes/conexion.php';
requiere_rol('cliente', '../../');

$uid   = usuario_id();
$ok    = '';
$error = '';

// Peso actual
$stmt = $conn->prepare("SELECT peso_kg FROM progresos_metricas WHERE id_cliente=? ORDER BY fecha_hora DESC LIMIT 1");
$stmt->bind_param('i', $uid); $stmt->execute();
$pesoActual = (float)($stmt->get_result()->fetch_assoc()['peso_kg'] ?? 0);
$stmt->close();

// Anamnesis
$stmt = $conn->prepare("SELECT * FROM fichas_anamnesis WHERE id_cliente=?");
$stmt->bind_param('i', $uid); $stmt->execute();
$a = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$a || empty($a['fecha_nacimiento']) || empty($a['altura_cm']) || $pesoActual <= 0) {
    header('Location: cliente_anamnesis.php'); exit;
}

// ============================================================
// POST
// ============================================================
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
                else $error = ($conn->errno === 1062) ? 'Ese email ya está en uso.' : 'Error.';
                $stmt->close();
            }
        }
        elseif ($accion === 'anamnesis') {
            $sexo    = $_POST['sexo'] ?? 'Hombre';
            $fnac    = $_POST['fecha_nacimiento'] ?? null;
            $altura  = (float)($_POST['altura_cm'] ?? 0);
            $pasos   = (int)($_POST['pasos_diarios'] ?? 7000);
            $dias    = (int)($_POST['dias_gym'] ?? 3);
            $minSes  = (int)($_POST['min_sesion'] ?? 60);
            $tipoE   = $_POST['tipo_entreno'] ?? 'mixto';
            $tipoT   = $_POST['tipo_trabajo'] ?? 'sentado';
            $alerg   = trim($_POST['alergias'] ?? '');
            $comF    = trim($_POST['comidas_fav'] ?? '');

            $objKcal = (int)($_POST['hidden_obj_kcal'] ?? 0);
            $factor  = (float)($_POST['hidden_factor_actividad'] ?? 1.4);

            // OJO: no tocamos obj_p, obj_c, obj_g, factor_p ni factor_g. Eso lo gestiona el dietista.
            $stmt = $conn->prepare(
                "UPDATE fichas_anamnesis SET
                   sexo=?, fecha_nacimiento=?, altura_cm=?, factor_actividad=?,
                   pasos_diarios=?, dias_gym=?, min_sesion=?, tipo_entreno=?, tipo_trabajo=?,
                   alergias=?, comidas_fav=?, obj_kcal=?
                 WHERE id_cliente=?"
            );
            $stmt->bind_param('ssddiiisssssii',
                $sexo, $fnac, $altura, $factor,
                $pasos, $dias, $minSes, $tipoE, $tipoT,
                $alerg, $comF, $objKcal, $uid);
            if ($stmt->execute()) $ok = 'Datos actualizados.';
            else $error = 'No se pudo actualizar.';
            $stmt->close();

            // Recargar
            $stmt = $conn->prepare("SELECT * FROM fichas_anamnesis WHERE id_cliente=?");
            $stmt->bind_param('i', $uid); $stmt->execute();
            $a = $stmt->get_result()->fetch_assoc();
            $stmt->close();
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

$stmt = $conn->prepare("SELECT nombre_completo, email FROM usuarios WHERE id=?");
$stmt->bind_param('i', $uid); $stmt->execute();
$u = $stmt->get_result()->fetch_assoc();
$stmt->close();

$g = function (string $k, $def) use ($a) {
    return isset($a[$k]) && $a[$k] !== null && $a[$k] !== '' ? $a[$k] : $def;
};

$base   = '../../';
$active = 'perfil';
$titulo = 'Mi perfil';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main class="page">

  <?php if ($ok):    ?><div class="alert alert-success"><?= e($ok) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

  <!-- DATOS DE CUENTA -->
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

  <!-- TDEE LIVE -->
  <aside class="tdee-live" id="tdee_live_panel">
    <div class="tdee-live-header"><span>⚡ Tu gasto calórico actual</span></div>
    <div class="alert alert-warning" id="tdee_avisos" hidden></div>
    <section class="tdee-card" id="tdee_card">
      <div class="tdee-total">
        <span class="tdee-total-num" id="prev_tdee_total">—</span>
        <span class="tdee-total-lbl">TDEE ponderado · objetivo diario</span>
      </div>
      <div class="tdee-breakdown">
        <div class="tdee-row"><span class="lbl">🔥 BMR</span><span class="val" id="prev_bmr">—</span></div>
        <div class="tdee-row"><span class="lbl">👟 NEAT pasos</span><span class="val" id="prev_neat_pasos">—</span></div>
        <div class="tdee-row"><span class="lbl">💼 NEAT trabajo</span><span class="val" id="prev_neat_trabajo">—</span></div>
        <div class="tdee-row"><span class="lbl">🏋️ EAT entreno</span><span class="val" id="prev_eat">—</span></div>
        <div class="tdee-row"><span class="lbl">🍴 TEF</span><span class="val" id="prev_tef">—</span></div>
      </div>
      <div class="tdee-days">
        <div class="tdee-day"><span class="tdee-day-lbl">Día entreno</span><span class="tdee-day-num" id="prev_tdee_entreno">—</span></div>
        <div class="tdee-day"><span class="tdee-day-lbl">Día descanso</span><span class="tdee-day-num" id="prev_tdee_descanso">—</span></div>
        <div class="tdee-day"><span class="tdee-day-lbl">Factor eq.</span><span class="tdee-day-num" id="prev_factor_eq">—</span></div>
      </div>
    </section>
  </aside>

  <!-- ANAMNESIS -->
  <form method="post" class="card">
    <h2 class="card-title">📋 Mis datos</h2>
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="accion" value="anamnesis">
    <input type="hidden" id="hidden_obj_kcal" name="hidden_obj_kcal">
    <input type="hidden" id="hidden_factor_actividad" name="hidden_factor_actividad">

    <div class="grid-2">
      <label class="field">
        <span class="field-label">Sexo</span>
        <select class="field-select" name="sexo">
          <?php foreach (['Hombre','Mujer','Otro'] as $s): ?>
            <option value="<?= e($s) ?>" <?= $g('sexo','Hombre') === $s ? 'selected' : '' ?>><?= e($s) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field">
        <span class="field-label">Fecha nac. <span class="text-muted" id="val_edad">—</span></span>
        <input class="field-input" type="date" name="fecha_nacimiento" required value="<?= e($g('fecha_nacimiento','')) ?>">
      </label>
    </div>

    <label class="field">
      <span class="field-label">Altura (cm)</span>
      <input class="field-input" type="number" step="0.1" min="100" max="250" name="altura_cm" required value="<?= e($g('altura_cm','170')) ?>">
    </label>

    <p class="text-soft">Peso de referencia: <strong><?= e(rtrim(rtrim((string)$pesoActual, '0'), '.')) ?> kg</strong>. Para cambiarlo, ve a <a href="cliente_progresos.php">Progresos</a>.</p>

    <label class="field">
      <span class="field-label">Pasos: <strong id="val_pasos_diarios"><?= (int)$g('pasos_diarios', 7000) ?></strong></span>
      <input type="range" class="slider" name="pasos_diarios" min="2000" max="20000" step="500" value="<?= (int)$g('pasos_diarios', 7000) ?>">
    </label>
    <label class="field">
      <span class="field-label">Días gym: <strong id="val_dias_gym"><?= (int)$g('dias_gym', 3) ?></strong></span>
      <input type="range" class="slider" name="dias_gym" min="0" max="7" step="1" value="<?= (int)$g('dias_gym', 3) ?>">
    </label>
    <label class="field">
      <span class="field-label">Min/sesión: <strong id="val_min_sesion"><?= (int)$g('min_sesion', 60) ?></strong></span>
      <input type="range" class="slider" name="min_sesion" min="15" max="180" step="5" value="<?= (int)$g('min_sesion', 60) ?>">
    </label>

    <p class="field-label">Tipo de entreno</p>
    <div class="radio-cards">
      <?php $entrenos = ['fuerza'=>'💪 Fuerza','cardio'=>'🏃 Cardio','mixto'=>'⚖️ Mixto','calistenia'=>'🤸 Calistenia','otro'=>'❓ Otro'];
      foreach ($entrenos as $k => $lbl): ?>
        <label class="radio-card">
          <input type="radio" name="tipo_entreno" value="<?= e($k) ?>" <?= $g('tipo_entreno','mixto') === $k ? 'checked' : '' ?>>
          <span><?= e($lbl) ?></span>
        </label>
      <?php endforeach; ?>
    </div>

    <p class="field-label">Tipo de trabajo</p>
    <div class="radio-cards">
      <?php $trabajos = ['sentado'=>'🪑 Sentado','de_pie'=>'🧍 De pie','caminando'=>'🚶 Caminando','fisico_leve'=>'🛠️ F. leve','fisico_intenso'=>'⛏️ F. intenso'];
      foreach ($trabajos as $k => $lbl): ?>
        <label class="radio-card">
          <input type="radio" name="tipo_trabajo" value="<?= e($k) ?>" <?= $g('tipo_trabajo','sentado') === $k ? 'checked' : '' ?>>
          <span><?= e($lbl) ?></span>
        </label>
      <?php endforeach; ?>
    </div>

    <label class="field">
      <span class="field-label">Alergias</span>
      <textarea class="field-textarea" name="alergias"><?= e($g('alergias','')) ?></textarea>
    </label>
    <label class="field">
      <span class="field-label">Preferencias</span>
      <textarea class="field-textarea" name="comidas_fav"><?= e($g('comidas_fav','')) ?></textarea>
    </label>

    <button type="submit" class="btn btn-primary btn-block">Guardar</button>
  </form>
  <!-- APARIENCIA / TEMA -->
<!-- APARIENCIA / TEMA -->
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

  <script>
  (function(){
    const btn   = document.getElementById('themeBtn');
    const label = document.getElementById('themeLabel');
    function pintar() {
      const t = window.AppTheme.get();
      btn.setAttribute('aria-checked', t === 'dark' ? 'true' : 'false');
      btn.classList.toggle('is-on', t === 'dark');
      label.textContent = t === 'dark' ? '🌙 Modo oscuro' : '☀️ Modo claro';
    }
    btn.addEventListener('click', () => { window.AppTheme.toggle(); pintar(); });
    pintar();
  })();
  </script>
  <!-- CONTRASEÑA -->
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

<script>window.PESO_REFERENCIA = <?= json_encode($pesoActual) ?>;</script>
<script src="<?= e($base) ?>js/anamnesis.js" defer></script>



<?php include __DIR__ . '/../../includes/footer.php'; ?>