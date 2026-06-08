<?php
require_once __DIR__ . '/../../includes/conexion.php';
requiere_rol('dietista', '../../');

$uid = usuario_id();
$idC = isset($_GET['id_cliente']) ? (int)$_GET['id_cliente'] : 0;
$ok    = '';
$error = '';

// Verificar propiedad del cliente
$stmt = $conn->prepare("SELECT id, nombre_completo, email FROM usuarios WHERE id=? AND id_dietista=? AND rol='cliente' AND activo=1");
$stmt->bind_param('ii', $idC, $uid); $stmt->execute();
$cliente = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$cliente) { header('Location: dietista_ficha.php'); exit; }

// Peso actual
$stmt = $conn->prepare("SELECT peso_kg FROM progresos_metricas WHERE id_cliente=? ORDER BY fecha_hora DESC LIMIT 1");
$stmt->bind_param('i', $idC); $stmt->execute();
$pesoActual = (float)($stmt->get_result()->fetch_assoc()['peso_kg'] ?? 0);
$stmt->close();

// Anamnesis
$stmt = $conn->prepare("SELECT * FROM fichas_anamnesis WHERE id_cliente=?");
$stmt->bind_param('i', $idC); $stmt->execute();
$a = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$a || empty($a['fecha_nacimiento']) || $pesoActual <= 0) {
    $base   = '../../';
    $active = 'clientes';
    $titulo = 'Estrategia · ' . $cliente['nombre_completo'];
    include __DIR__ . '/../../includes/sidebar.php';
    ?>
    <main class="page">
      <div class="alert alert-warning">
        Este cliente aún no ha completado su cuestionario inicial o no tiene peso registrado.
      </div>
      <a class="btn btn-outline btn-block" href="dietista_ficha.php?id=<?= $idC ?>">← Volver a la ficha</a>
    </main>
    <?php include __DIR__ . '/../../includes/footer.php'; ?>
    <?php
    exit;
}

// POST: guardar estrategia (solo nombre + factor_p + factor_g)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $error = 'Sesión expirada.';
    } else {
        $nombre = mb_substr(trim((string)($_POST['nombre'] ?? '')), 0, 80);
        $fP     = (float)($_POST['factor_p'] ?? 2.0);
        $fG     = (float)($_POST['factor_g'] ?? 0.9);

        if ($nombre === '')                   $error = 'Pon un nombre a la estrategia.';
        elseif ($fP < 0.5 || $fP > 4.0)       $error = 'Factor proteínas fuera de rango.';
        elseif ($fG < 0.3 || $fG > 2.0)       $error = 'Factor grasas fuera de rango.';

        if (!$error) {
            // Solo actualizamos los factores en la ficha (kcal se calcula luego al crear la dieta)
            $stmt = $conn->prepare(
                "UPDATE fichas_anamnesis SET
                   factor_p=?, factor_g=?, fecha_estrategia=NOW()
                 WHERE id_cliente=?"
            );
            $stmt->bind_param('ddi', $fP, $fG, $idC);
            if ($stmt->execute()) {
                $stmt->close();
                // Guardamos como entrada en el historial (kcal y gramos en 0 — se calcularán al crear la dieta)
                $kcalH = 0; $gp = 0; $gc = 0; $gg = 0;
                $stmt2 = $conn->prepare(
                    "INSERT INTO historial_estrategias (id_cliente, id_dietista, nombre, kcal, factor_p, factor_g, gramos_p, gramos_c, gramos_g)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt2->bind_param('iisidddii', $idC, $uid, $nombre, $kcalH, $fP, $fG, $gp, $gc, $gg);
                $stmt2->execute(); $stmt2->close();
                $ok = 'Estrategia guardada.';
            } else {
                $error = 'No se pudo guardar.';
                $stmt->close();
            }

            // recargar
            $stmt = $conn->prepare("SELECT * FROM fichas_anamnesis WHERE id_cliente=?");
            $stmt->bind_param('i', $idC); $stmt->execute();
            $a = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
    }
}

// Histórico de estrategias
$stmt = $conn->prepare(
    "SELECT id, nombre, factor_p, factor_g, fecha
     FROM historial_estrategias
     WHERE id_cliente=? AND id_dietista=?
     ORDER BY fecha DESC, id DESC
     LIMIT 30"
);
$stmt->bind_param('ii', $idC, $uid); $stmt->execute();
$historial = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Edad
$edad = '';
if (!empty($a['fecha_nacimiento'])) {
    $bd = new DateTime($a['fecha_nacimiento']);
    $now = new DateTime('today');
    $edad = $bd->diff($now)->y;
}

$base   = '../../';
$active = 'clientes';
$titulo = 'Estrategia · ' . $cliente['nombre_completo'];
include __DIR__ . '/../../includes/sidebar.php';
?>
<main class="page">

  <?php if ($ok):    ?><div class="alert alert-success"><?= e($ok) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

  <article class="card">
    <h2 class="card-title">🎯 Estrategia de <?= e($cliente['nombre_completo']) ?></h2>
    <a class="btn btn-outline btn-mini" href="dietista_ficha.php?id=<?= $idC ?>">← Volver a la ficha</a>
  </article>

  <!-- Resumen del cliente (no editable aquí) -->
  <section class="card">
    <h3 class="card-title">📋 Datos actuales del cliente</h3>
    <div class="estr-resumen-grid">
      <div class="estr-resumen-item"><span class="estr-resumen-lbl">Sexo</span><span class="estr-resumen-val"><?= e($a['sexo']) ?></span></div>
      <div class="estr-resumen-item"><span class="estr-resumen-lbl">Edad</span><span class="estr-resumen-val"><?= e((string)$edad) ?> años</span></div>
      <div class="estr-resumen-item"><span class="estr-resumen-lbl">Altura</span><span class="estr-resumen-val"><?= e(rtrim(rtrim((string)$a['altura_cm'], '0'), '.')) ?> cm</span></div>
      <div class="estr-resumen-item"><span class="estr-resumen-lbl">Peso</span><span class="estr-resumen-val"><?= e(rtrim(rtrim((string)$pesoActual, '0'), '.')) ?> kg</span></div>
      <div class="estr-resumen-item"><span class="estr-resumen-lbl">Pasos/día</span><span class="estr-resumen-val"><?= (int)$a['pasos_diarios'] ?></span></div>
      <div class="estr-resumen-item"><span class="estr-resumen-lbl">Días gym</span><span class="estr-resumen-val"><?= (int)$a['dias_gym'] ?></span></div>
      <div class="estr-resumen-item"><span class="estr-resumen-lbl">Min/sesión</span><span class="estr-resumen-val"><?= (int)$a['min_sesion'] ?></span></div>
      <div class="estr-resumen-item"><span class="estr-resumen-lbl">Entreno</span><span class="estr-resumen-val"><?= e($a['tipo_entreno']) ?></span></div>
      <div class="estr-resumen-item"><span class="estr-resumen-lbl">Trabajo</span><span class="estr-resumen-val"><?= e($a['tipo_trabajo']) ?></span></div>
    </div>
  </section>

  <!-- Inputs ocultos con los datos del cliente para que el JS calcule el TDEE informativo -->
  <input type="hidden" name="sexo" value="<?= e($a['sexo']) ?>">
  <input type="hidden" name="fecha_nacimiento" value="<?= e($a['fecha_nacimiento']) ?>">
  <input type="hidden" name="altura_cm" value="<?= e($a['altura_cm']) ?>">
  <input type="hidden" name="pasos_diarios" value="<?= e($a['pasos_diarios']) ?>">
  <input type="hidden" name="dias_gym" value="<?= e($a['dias_gym']) ?>">
  <input type="hidden" name="min_sesion" value="<?= e($a['min_sesion']) ?>">
  <input type="hidden" name="tipo_entreno" value="<?= e($a['tipo_entreno']) ?>">
  <input type="hidden" name="tipo_trabajo" value="<?= e($a['tipo_trabajo']) ?>">

  <!-- TDEE LIVE (informativo) -->
  <aside class="tdee-live" id="tdee_live_panel">
    <div class="tdee-live-header"><span>⚡ Gasto calórico de referencia</span></div>
    <div class="alert alert-warning" id="tdee_avisos" hidden></div>
    <section class="tdee-card" id="tdee_card">
      <div class="tdee-total">
        <span class="tdee-total-num" id="prev_tdee_total">—</span>
        <span class="tdee-total-lbl">TDEE ponderado</span>
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

  <!-- Form de ajustes de estrategia -->
  <form method="post" class="card">
    <h3 class="card-title">⚙️ Ajustes de estrategia</h3>
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

    <label class="field">
      <span class="field-label">Nombre de la estrategia</span>
      <input class="field-input" type="text" name="nombre" maxlength="80" required
             placeholder="Ej: Definición S4, Volumen invierno...">
    </label>

    <label class="field">
      <span class="field-label">Factor proteínas: <strong id="val_factor_p"><?= e($a['factor_p']) ?></strong> g/kg</span>
      <input type="range" class="slider" name="factor_p" min="1.0" max="3.5" step="0.1" value="<?= e($a['factor_p']) ?>">
    </label>

    <label class="field">
      <span class="field-label">Factor grasas: <strong id="val_factor_g"><?= e($a['factor_g']) ?></strong> g/kg</span>
      <input type="range" class="slider" name="factor_g" min="0.4" max="1.8" step="0.1" value="<?= e($a['factor_g']) ?>">
    </label>

    <p class="text-soft">Las kcal y los carbos se calculan automáticamente al asignar la dieta.</p>

    <button type="submit" class="btn btn-primary btn-block">💾 Guardar estrategia</button>
  </form>

  <!-- Historial -->
  <?php if (!empty($historial)): ?>
    <section class="card">
      <h3 class="card-title">📜 Historial de estrategias (<?= count($historial) ?>)</h3>
      <ul class="ficha-estr-hist-list" role="list">
        <?php foreach ($historial as $h): ?>
          <li class="ficha-estr-hist-item">
            <div class="ficha-estr-hist-info">
              <span class="ficha-estr-hist-fecha"><?= e(date('d/m/Y H:i', strtotime($h['fecha']))) ?></span>
              <span class="ficha-estr-hist-vals">
                <span class="ficha-estr-hist-nombre"><?= e($h['nombre'] ?: '—') ?></span>
                · <span class="mc-p">P <?= e($h['factor_p']) ?> g/kg</span>
                · <span class="mc-g">G <?= e($h['factor_g']) ?> g/kg</span>
              </span>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>

</main>

<script>
  window.PESO_REFERENCIA = <?= json_encode($pesoActual) ?>;
</script>
<script src="<?= e($base) ?>js/anamnesis.js" defer></script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>