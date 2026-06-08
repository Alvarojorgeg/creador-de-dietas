<?php
require_once __DIR__ . '/../../includes/conexion.php';
requiere_rol('cliente', '../../');

$uid   = usuario_id();
$error = '';

// Último peso registrado (si existe)
$stmt = $conn->prepare("SELECT peso_kg FROM progresos_metricas WHERE id_cliente=? ORDER BY fecha_hora DESC LIMIT 1");
$stmt->bind_param('i', $uid);
$stmt->execute();
$pesoActual = (float)($stmt->get_result()->fetch_assoc()['peso_kg'] ?? 0);
$stmt->close();

// Anamnesis previa
$stmt = $conn->prepare("SELECT * FROM fichas_anamnesis WHERE id_cliente=?");
$stmt->bind_param('i', $uid);
$stmt->execute();
$a = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

// ============================================================
// POST: guardar cuestionario
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $error = 'Sesión expirada.';
    } else {
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

        $pesoIni  = (float)($_POST['peso_inicial'] ?? 0);
        $grasaIni = ($_POST['grasa_inicial'] ?? '') === '' ? null : (float)$_POST['grasa_inicial'];

        if (!in_array($sexo, ['Hombre','Mujer','Otro'], true)) $error = 'Sexo inválido.';
        elseif (!$fnac || strtotime($fnac) === false)          $error = 'Fecha de nacimiento inválida.';
        elseif ($altura < 100 || $altura > 250)                $error = 'Altura inválida.';
        elseif ($pesoActual <= 0 && $pesoIni <= 0)             $error = 'Necesitamos tu peso inicial.';

        if (!$error) {
            $conn->begin_transaction();
            try {
                // 1) Peso inicial si no había
                if ($pesoActual <= 0 && $pesoIni > 0) {
                    $ahora = date('Y-m-d H:i:s');
                    $stmt = $conn->prepare(
                        "INSERT INTO progresos_metricas (id_cliente, fecha_hora, peso_kg, porcentaje_grasa, notas_cliente)
                         VALUES (?, ?, ?, ?, 'Peso inicial · cuestionario')"
                    );
                    $stmt->bind_param('isdd', $uid, $ahora, $pesoIni, $grasaIni);
                    $stmt->execute();
                    $stmt->close();
                    $pesoActual = $pesoIni;
                }

                // 2) INSERT/UPDATE anamnesis (sin tocar factor_p/factor_g/obj_p/c/g)
                if (!$a) {
                    // Primera vez: usar defaults internos para macros provisionales
                    $factorP_def = 2.0;
                    $factorG_def = 0.9;
                    $gP = $pesoActual * $factorP_def;
                    $gG = $pesoActual * $factorG_def;
                    $kP = $gP * 4; $kG = $gG * 9;
                    $kC = max(0, $objKcal - $kP - $kG);
                    $gC = $kC / 4;

                    $stmt = $conn->prepare(
                        "INSERT INTO fichas_anamnesis
                           (id_cliente, sexo, fecha_nacimiento, altura_cm, factor_actividad,
                            pasos_diarios, dias_gym, min_sesion, tipo_entreno, tipo_trabajo,
                            alergias, comidas_fav, obj_kcal, obj_p, obj_c, obj_g,
                            factor_p, factor_g, fecha_estrategia)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, NOW())"
                    );
                    $stmt->bind_param('issddiiisssssddddd',
                        $uid, $sexo, $fnac, $altura, $factor,
                        $pasos, $dias, $minSes, $tipoE, $tipoT,
                        $alerg, $comF, $objKcal, $gP, $gC, $gG,
                        $factorP_def, $factorG_def);
                    $stmt->execute();
                    $stmt->close();
                } else {
                    // UPDATE: solo tocar lo del cliente. Macros y factores quedan tal cual.
                    $stmt = $conn->prepare(
                        "UPDATE fichas_anamnesis SET
                           sexo=?, fecha_nacimiento=?, altura_cm=?, factor_actividad=?,
                           pasos_diarios=?, dias_gym=?, min_sesion=?, tipo_entreno=?, tipo_trabajo=?,
                           alergias=?, comidas_fav=?, obj_kcal=?
                         WHERE id_cliente=?"
                    );
                    // Fíjate que ahora es 'ssddiiissssii' (13 letras)
                  $stmt->bind_param('ssddiiissssii',
                      $sexo, $fnac, $altura, $factor,
                      $pasos, $dias, $minSes, $tipoE, $tipoT,
                      $alerg, $comF, $objKcal, $uid
                  );
                    $stmt->execute();
                    $stmt->close();
                }

                $conn->commit();
                header('Location: cliente_dashboard.php?bienvenida=1');
                exit;
            } catch (Exception $ex) {
                $conn->rollback();
                $error = 'No se pudo guardar: ' . $ex->getMessage();
            }
        }
    }
}

$g = function (string $k, $def) use ($a) {
    return isset($a[$k]) && $a[$k] !== null && $a[$k] !== '' ? $a[$k] : $def;
};

$base   = '../../';
$active = 'perfil';
$titulo = 'Cuestionario inicial';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main class="page anamnesis-wizard">

  <header class="anam-hero">
    <div class="anam-hero-icon">🎯</div>
    <h1 class="anam-hero-title">Vamos a calcular tu plan</h1>
    <p class="anam-hero-sub">
      Rellena este cuestionario y calcularemos tu gasto calórico real (BMR + NEAT + entreno) en tiempo real. Tu dietista ajustará luego el reparto de macros.
    </p>
  </header>

  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

  <!-- TDEE LIVE -->
  <aside class="tdee-live" id="tdee_live_panel">
    <div class="tdee-live-header"><span>⚡ Tu gasto calórico en tiempo real</span></div>
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

  <form method="post" class="card" novalidate>
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" id="hidden_obj_kcal" name="hidden_obj_kcal">
    <input type="hidden" id="hidden_factor_actividad" name="hidden_factor_actividad">

    <h2 class="card-title">👤 Sobre ti</h2>
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
        <span class="field-label">Fecha de nacimiento <span class="text-muted" id="val_edad">—</span></span>
        <input class="field-input" type="date" name="fecha_nacimiento" required value="<?= e($g('fecha_nacimiento','')) ?>">
      </label>
    </div>

    <label class="field">
      <span class="field-label">Altura (cm)</span>
      <input class="field-input" type="number" step="0.1" min="100" max="250" name="altura_cm" required value="<?= e($g('altura_cm','170')) ?>">
    </label>

    <?php if ($pesoActual <= 0): ?>
      <h3 class="card-title">⚖️ Tu peso inicial</h3>
      <p class="text-soft">Necesitamos un peso de partida.</p>
      <div class="grid-2">
        <label class="field">
          <span class="field-label">Peso actual (kg)</span>
          <input class="field-input" type="number" step="0.1" min="20" max="400" name="peso_inicial" required
                 oninput="window.PESO_REFERENCIA = parseFloat(this.value) || 0; document.querySelector('[name=altura_cm]').dispatchEvent(new Event('input'));">
        </label>
        <label class="field">
          <span class="field-label">% grasa <span class="text-muted">(opcional)</span></span>
          <input class="field-input" type="number" step="0.1" min="0" max="80" name="grasa_inicial">
        </label>
      </div>
    <?php endif; ?>

    <h2 class="card-title">🚶 Actividad diaria</h2>

    <label class="field">
      <span class="field-label">Pasos diarios: <strong id="val_pasos_diarios"><?= (int)$g('pasos_diarios', 7000) ?></strong></span>
      <input type="range" class="slider" name="pasos_diarios" min="2000" max="20000" step="500" value="<?= (int)$g('pasos_diarios', 7000) ?>">
    </label>
    <label class="field">
      <span class="field-label">Días de gym/semana: <strong id="val_dias_gym"><?= (int)$g('dias_gym', 3) ?></strong></span>
      <input type="range" class="slider" name="dias_gym" min="0" max="7" step="1" value="<?= (int)$g('dias_gym', 3) ?>">
    </label>
    <label class="field">
      <span class="field-label">Minutos por sesión: <strong id="val_min_sesion"><?= (int)$g('min_sesion', 60) ?></strong></span>
      <input type="range" class="slider" name="min_sesion" min="15" max="180" step="5" value="<?= (int)$g('min_sesion', 60) ?>">
    </label>

    <h2 class="card-title">🏋️ Tipo de entreno</h2>
    <div class="radio-cards">
      <?php $entrenos = ['fuerza'=>'💪 Fuerza','cardio'=>'🏃 Cardio','mixto'=>'⚖️ Mixto','calistenia'=>'🤸 Calistenia','otro'=>'❓ Otro'];
      foreach ($entrenos as $k => $lbl): ?>
        <label class="radio-card">
          <input type="radio" name="tipo_entreno" value="<?= e($k) ?>" <?= $g('tipo_entreno','mixto') === $k ? 'checked' : '' ?>>
          <span><?= e($lbl) ?></span>
        </label>
      <?php endforeach; ?>
    </div>

    <h2 class="card-title">💼 Tipo de trabajo</h2>
    <div class="radio-cards">
      <?php $trabajos = ['sentado'=>'🪑 Sentado','de_pie'=>'🧍 De pie','caminando'=>'🚶 Caminando','fisico_leve'=>'🛠️ F. leve','fisico_intenso'=>'⛏️ F. intenso'];
      foreach ($trabajos as $k => $lbl): ?>
        <label class="radio-card">
          <input type="radio" name="tipo_trabajo" value="<?= e($k) ?>" <?= $g('tipo_trabajo','sentado') === $k ? 'checked' : '' ?>>
          <span><?= e($lbl) ?></span>
        </label>
      <?php endforeach; ?>
    </div>

    <h2 class="card-title">🥗 Preferencias</h2>
    <label class="field">
      <span class="field-label">Alergias / intolerancias</span>
      <textarea class="field-textarea" name="alergias"><?= e($g('alergias','')) ?></textarea>
    </label>
    <label class="field">
      <span class="field-label">Comidas favoritas / preferencias</span>
      <textarea class="field-textarea" name="comidas_fav"><?= e($g('comidas_fav','')) ?></textarea>
    </label>

    <button type="submit" class="btn btn-primary btn-block">Guardar y empezar</button>
  </form>

</main>

<script>window.PESO_REFERENCIA = <?= json_encode($pesoActual) ?>;</script>
<script src="<?= e($base) ?>js/anamnesis.js" defer></script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>