<?php
require_once __DIR__ . '/../../includes/conexion.php';
requiere_rol('cliente', '../../');

$uid = usuario_id();

// ============================================================
// HELPERS — bienestar (igual lógica que dietista)
// Convención: 1=mal, 5=bien. Hambre se invierte (5=mucha hambre=malo).
// ============================================================
function bienestar_score(array $ck): float {
    $hambreInv = 6 - (int)$ck['hambre'];
    $sum = $hambreInv
         + (int)$ck['energia']
         + (int)$ck['sueno']
         + (int)$ck['cumplimiento_dieta']
         + (int)$ck['animo'];
    return round($sum / 5, 2);
}
function color_metrica(string $tipo, int $v): string {
    if ($tipo === 'hambre') {
        if ($v <= 2) return 'good';
        if ($v <= 3) return 'warn';
        return 'bad';
    }
    if ($v >= 4) return 'good';
    if ($v >= 3) return 'warn';
    return 'bad';
}

// ============================================================
// Semana ACTUAL (lunes a domingo)
// ============================================================
$hoy   = new DateTime('today');
$dow   = (int)$hoy->format('N');
$lunesActual    = (clone $hoy)->modify('-' . ($dow - 1) . ' days');
$semanaActStr   = $lunesActual->format('Y-m-d');

// ============================================================
// Si llega ?semana=YYYY-MM-DD → editar esa semana (debe ser un lunes <= hoy)
// ============================================================
$semanaPedida = $_GET['semana'] ?? '';
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $semanaPedida)) {
    $sDt = new DateTime($semanaPedida);
    $sDow = (int)$sDt->format('N');
    if ($sDow !== 1) $sDt->modify('-' . ($sDow - 1) . ' days');
    if ($sDt > $lunesActual) $sDt = clone $lunesActual;
    $semanaEdit = $sDt;
} else {
    $semanaEdit = clone $lunesActual;
}
$semanaInicio   = $semanaEdit->format('Y-m-d');
$domingoSel     = (clone $semanaEdit)->modify('+6 days');
$esSemanaActual = ($semanaInicio === $semanaActStr);

$ok = ''; $error = '';

// ============================================================
// POST: guardar el check-in para la semana indicada
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $error = 'Sesión expirada. Recarga la página.';
    } else {
        // Validar semana del POST
        $semPost = $_POST['semana_inicio'] ?? $semanaInicio;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $semPost)) $semPost = $semanaInicio;
        $sDt = new DateTime($semPost);
        if ((int)$sDt->format('N') !== 1) $sDt->modify('-' . ((int)$sDt->format('N') - 1) . ' days');
        if ($sDt > $lunesActual) $sDt = clone $lunesActual;
        $semGuardar = $sDt->format('Y-m-d');

        $hambre  = (int)($_POST['hambre']  ?? 0);
        $energia = (int)($_POST['energia'] ?? 0);
        $sueno   = (int)($_POST['sueno']   ?? 0);
        $cumple  = (int)($_POST['cumplimiento_dieta'] ?? 0);
        $animo   = (int)($_POST['animo']   ?? 0);
        $obs     = trim((string)($_POST['observaciones'] ?? ''));
        if (mb_strlen($obs) > 500) $obs = mb_substr($obs, 0, 500);

        $todas = [$hambre, $energia, $sueno, $cumple, $animo];
        $valido = true;
        foreach ($todas as $vv) { if ($vv < 1 || $vv > 5) { $valido = false; break; } }

        if (!$valido) {
            $error = 'Responde todas las preguntas con un valor del 1 al 5.';
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO checkins_semanales
                   (id_cliente, semana_inicio, hambre, energia, sueno, cumplimiento_dieta, animo, observaciones)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   hambre=VALUES(hambre),
                   energia=VALUES(energia),
                   sueno=VALUES(sueno),
                   cumplimiento_dieta=VALUES(cumplimiento_dieta),
                   animo=VALUES(animo),
                   observaciones=VALUES(observaciones)"
            );
            $stmt->bind_param('isiiiiis', $uid, $semGuardar, $hambre, $energia, $sueno, $cumple, $animo, $obs);
            if ($stmt->execute()) {
                $ok = '¡Check-in guardado! 💪';
                $semanaInicio   = $semGuardar;
                $semanaEdit     = new DateTime($semGuardar);
                $domingoSel     = (clone $semanaEdit)->modify('+6 days');
                $esSemanaActual = ($semanaInicio === $semanaActStr);
            } else {
                $error = 'No se pudo guardar el check-in.';
            }
            $stmt->close();
        }
    }
}

// Cargar check-in de la semana actual (la que se edita)
$stmt = $conn->prepare("SELECT * FROM checkins_semanales WHERE id_cliente = ? AND semana_inicio = ? LIMIT 1");
$stmt->bind_param('is', $uid, $semanaInicio);
$stmt->execute();
$actual = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Histórico
$stmt = $conn->prepare(
    "SELECT semana_inicio, hambre, energia, sueno, cumplimiento_dieta, animo, observaciones
     FROM checkins_semanales WHERE id_cliente = ?
     ORDER BY semana_inicio DESC LIMIT 12"
);
$stmt->bind_param('i', $uid);
$stmt->execute();
$historico = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$v = function (string $k, $def = 0) use ($actual) {
    return $actual && isset($actual[$k]) ? (int)$actual[$k] : (int)$def;
};

$preguntas = [
    ['hambre',             '😋', 'Nivel de hambre',          'Sin hambre',         'Mucha hambre'],
    ['energia',            '⚡', 'Energía general',           'Agotado/a',          'Lleno/a de energía'],
    ['sueno',              '😴', 'Calidad del sueño',         'Muy mala',           'Excelente'],
    ['cumplimiento_dieta', '🍽️', 'Cumplimiento de la dieta',  'No la he seguido',   'La he seguido al 100%'],
    ['animo',              '😊', 'Estado de ánimo',           'Muy bajo',           'Muy bueno'],
];

$base   = '../../';
$active = 'checkin';
$titulo = 'Check-in semanal';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main class="page">

  <header class="ck-header">
    <h2 class="h1">📝 Check-in semanal</h2>
    <?php if ($esSemanaActual): ?>
      <p class="text-soft">
        Semana actual: <strong><?= e($semanaEdit->format('d/m')) ?></strong> al
        <strong><?= e($domingoSel->format('d/m/Y')) ?></strong>
      </p>
    <?php else: ?>
      <p class="text-soft">
        ✏️ Editando: <strong><?= e($semanaEdit->format('d/m')) ?></strong> al
        <strong><?= e($domingoSel->format('d/m/Y')) ?></strong>
      </p>
      <a class="btn btn-outline btn-mini" href="cliente_checkin.php">← Volver a semana actual</a>
    <?php endif; ?>

    <?php if ($actual): ?>
      <div class="ck-badge ck-badge--ok">✓ Ya completado · puedes editarlo</div>
    <?php else: ?>
      <div class="ck-badge ck-badge--pendiente">Pendiente</div>
    <?php endif; ?>
  </header>

  <?php if ($ok):    ?><div class="alert alert-success" role="status"><?= e($ok) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"  role="alert"><?= e($error) ?></div><?php endif; ?>

  <form method="post" class="card ck-form" novalidate>
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="semana_inicio" value="<?= e($semanaInicio) ?>">

    <?php foreach ($preguntas as $p):
      [$name, $emoji, $label, $lbl1, $lbl5] = $p;
      $selected = $v($name);
    ?>
      <fieldset class="ck-q">
        <legend class="ck-q-legend">
          <span class="ck-q-emoji" aria-hidden="true"><?= $emoji ?></span>
          <span class="ck-q-text"><?= e($label) ?></span>
        </legend>
        <div class="ck-scale" role="radiogroup" aria-label="<?= e($label) ?>">
          <?php for ($i = 1; $i <= 5; $i++): ?>
            <label class="ck-chip">
              <input type="radio" name="<?= e($name) ?>" value="<?= $i ?>"
                     <?= $selected === $i ? 'checked' : '' ?> required>
              <span class="ck-chip-num"><?= $i ?></span>
            </label>
          <?php endfor; ?>
        </div>
        <div class="ck-scale-labels">
          <span><?= e($lbl1) ?></span>
          <span><?= e($lbl5) ?></span>
        </div>
      </fieldset>
    <?php endforeach; ?>

    <label class="field">
      <span class="field-label">Observaciones (opcional)</span>
      <textarea class="field-textarea" name="observaciones" maxlength="500"
                placeholder="¿Algo que quieras contarle a tu dietista esta semana?"><?= e($actual['observaciones'] ?? '') ?></textarea>
      <span class="field-help">Máx. 500 caracteres.</span>
    </label>

    <button type="submit" class="btn btn-primary btn-block">
      <?= $actual ? '💾 Actualizar check-in' : '✅ Enviar check-in' ?>
    </button>
  </form>

  <?php if ($actual):
    $bien = bienestar_score($actual);
    $cls = $bien >= 4 ? 'ok' : ($bien >= 2.5 ? 'warn' : 'bad');
  ?>
  <section class="card ck-current-score">
    <h3 class="card-title">📊 Tu bienestar de esta semana</h3>
    <div class="ck-big-score ck-big-score--<?= e($cls) ?>">
      <span class="ck-big-num"><?= e((string)$bien) ?></span>
      <span class="ck-big-max">/5</span>
    </div>
    <p class="text-muted text-center" style="font-size:11px;margin-top:var(--sp-2);">
      💡 La media tiene en cuenta que mucha hambre (5) es malo: se invierte automáticamente.
    </p>
  </section>
  <?php endif; ?>

  <?php if ($historico): ?>
    <section class="card">
      <h3 class="card-title">📅 Tus últimas semanas <span class="text-muted ck-help-edit">· toca una para editarla</span></h3>
      <ul class="ck-hist" role="list">
        <?php foreach ($historico as $i => $h):
          $ini  = new DateTime($h['semana_inicio']);
          $fin  = (clone $ini)->modify('+6 days');
          $bien = bienestar_score($h);
          $cls  = $bien >= 4 ? 'ok' : ($bien >= 2.5 ? 'warn' : 'bad');
          $prev = $historico[$i + 1] ?? null;
          $diff = $prev !== null ? round($bien - bienestar_score($prev), 2) : null;
          $esEnEdicion = $h['semana_inicio'] === $semanaInicio;
        ?>
          <li class="ck-hist-item ck-hist-item--<?= e($cls) ?><?= $esEnEdicion ? ' is-editing' : '' ?>">
            <a class="ck-hist-link" href="?semana=<?= e($h['semana_inicio']) ?>">
              <div class="ck-hist-fecha">
                <strong><?= e($ini->format('d/m')) ?> – <?= e($fin->format('d/m/Y')) ?></strong>
                <span class="ck-hist-bien">
                  <strong><?= e((string)$bien) ?>/5</strong>
                  <?php if ($diff !== null): ?>
                    <span class="ck-diff <?= $diff > 0 ? 'is-up-good' : ($diff < 0 ? 'is-down-bad' : 'is-neutral') ?>">
                      <?= $diff > 0 ? '▲ +' : ($diff < 0 ? '▼ ' : '·  ') ?><?= e((string)$diff) ?>
                    </span>
                  <?php endif; ?>
                </span>
              </div>
              <ul class="ck-hist-vals" role="list">
                <li class="ck-m ck-m--<?= color_metrica('hambre', (int)$h['hambre']) ?>">
                  <span class="ck-m-emoji">😋</span>
                  <span class="ck-m-label">Hambre</span>
                  <span class="ck-m-val"><?= (int)$h['hambre'] ?></span>
                </li>
                <li class="ck-m ck-m--<?= color_metrica('energia', (int)$h['energia']) ?>">
                  <span class="ck-m-emoji">⚡</span>
                  <span class="ck-m-label">Energía</span>
                  <span class="ck-m-val"><?= (int)$h['energia'] ?></span>
                </li>
                <li class="ck-m ck-m--<?= color_metrica('sueno', (int)$h['sueno']) ?>">
                  <span class="ck-m-emoji">😴</span>
                  <span class="ck-m-label">Sueño</span>
                  <span class="ck-m-val"><?= (int)$h['sueno'] ?></span>
                </li>
                <li class="ck-m ck-m--<?= color_metrica('dieta', (int)$h['cumplimiento_dieta']) ?>">
                  <span class="ck-m-emoji">🍽️</span>
                  <span class="ck-m-label">Dieta</span>
                  <span class="ck-m-val"><?= (int)$h['cumplimiento_dieta'] ?></span>
                </li>
                <li class="ck-m ck-m--<?= color_metrica('animo', (int)$h['animo']) ?>">
                  <span class="ck-m-emoji">😊</span>
                  <span class="ck-m-label">Ánimo</span>
                  <span class="ck-m-val"><?= (int)$h['animo'] ?></span>
                </li>
              </ul>
              <?php if (!empty($h['observaciones'])): ?>
                <p class="ck-hist-obs">"<?= e($h['observaciones']) ?>"</p>
              <?php endif; ?>
              <?php if ($esEnEdicion): ?>
                <p class="ck-hist-editing">✏️ Esta es la que estás editando arriba</p>
              <?php endif; ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>

</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
