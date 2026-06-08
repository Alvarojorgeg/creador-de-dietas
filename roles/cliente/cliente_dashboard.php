<?php
require_once __DIR__ . '/../../includes/conexion.php';
require_once __DIR__ . '/../../includes/predicciones.php';
requiere_rol('cliente', '../../');

$uid = usuario_id();

// Si la anamnesis no está completa, forzar cuestionario inicial
$stmt = $conn->prepare("SELECT altura_cm, fecha_nacimiento FROM fichas_anamnesis WHERE id_cliente=?");
$stmt->bind_param('i', $uid);
$stmt->execute();
$_anam = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$_anam || empty($_anam['fecha_nacimiento']) || empty($_anam['altura_cm'])) {
    header('Location: cliente_anamnesis.php');
    exit;
}

// --- Lunes de esta semana (para el check-in) ---
$hoy  = new DateTime('today');
$dow  = (int)$hoy->format('N');
$lunes = (clone $hoy)->modify('-' . ($dow - 1) . ' days');
$semanaInicio = $lunes->format('Y-m-d');
$hoyStr       = $hoy->format('Y-m-d');

// --- Dieta asignada para hoy ---
$stmt = $conn->prepare(
    "SELECT d.nombre, d.icono, d.color, d.kcal_objetivo, d.prot_objetivo, d.carb_objetivo, d.grasas_objetivo
     FROM calendario_asignaciones c
     JOIN dietas_base d ON d.id = c.id_dieta
     WHERE c.id_cliente = ? AND c.fecha_asignada = ?
     LIMIT 1"
);
$stmt->bind_param('is', $uid, $hoyStr);
$stmt->execute();
$dietaHoy = $stmt->get_result()->fetch_assoc();
$stmt->close();

// --- ¿Check-in de esta semana hecho? ---
$stmt = $conn->prepare(
    "SELECT id, hambre, energia, sueno, cumplimiento_dieta, animo
     FROM checkins_semanales
     WHERE id_cliente = ? AND semana_inicio = ?
     LIMIT 1"
);
$stmt->bind_param('is', $uid, $semanaInicio);
$stmt->execute();
$checkinSemana = $stmt->get_result()->fetch_assoc();
$stmt->close();

// --- Próxima consulta ---
$stmt = $conn->prepare(
    "SELECT c.fecha, c.tipo, u.nombre_completo AS dietista
     FROM consultas c
     JOIN usuarios u ON u.id = c.id_dietista
     WHERE c.id_cliente = ? AND c.fecha >= NOW()
     ORDER BY c.fecha ASC LIMIT 1"
);
$stmt->bind_param('i', $uid);
$stmt->execute();
$proximaConsulta = $stmt->get_result()->fetch_assoc();
$stmt->close();

// --- Última medida ---
$stmt = $conn->prepare(
    "SELECT fecha, cintura, cadera, pecho
     FROM medidas_corporales
     WHERE id_cliente = ?
     ORDER BY fecha DESC LIMIT 1"
);
$stmt->bind_param('i', $uid);
$stmt->execute();
$ultimaMedida = $stmt->get_result()->fetch_assoc();
$stmt->close();

// --- Predicciones (Feature 2) ---
$pred = predecir_cliente($conn, $uid);

// Helper de formato kg
function fmt_kg(?float $v, int $dec = 2): string {
    if ($v === null) return '—';
    $abs = abs($v);
    $s = ($v < 0 ? '+' : '−');  // truco: negativo = sube → +; positivo = baja → −
    if ($abs < 0.005) return '0 kg';
    return $s . number_format($abs, $dec, ',', '.') . ' kg';
}
function fmt_g_dia(?float $v): string {
    if ($v === null) return '—';
    $gr = $v * 1000;
    $abs = abs($gr);
    $s = ($v < 0 ? '+' : '−');
    if ($abs < 0.5) return '0 g';
    return $s . number_format($abs, 0, ',', '.') . ' g';
}

// Para el sidebar
$base   = '../../';
$active = 'dashboard';
$titulo = 'Inicio';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main class="page">

  <section class="dash-hero">
    <p class="dash-greeting">¡Hola, <?= e(strtok($_SESSION['usuario_nombre'], ' ')) ?>!</p>
    <h2 class="dash-day"><?= e(ucfirst(strftime_es($hoy))) ?></h2>
  </section>

  <!-- TARJETA: PREDICCIONES Y ESTADÍSTICAS (DINÁMICA por mes del calendario) -->
  <article class="card dash-card pred-card" id="pred_card">
    <header class="dash-card-header">
      <h3 class="card-title">
        🔮 Predicciones · <span id="pred_mes_lbl"><?= e(ucfirst(strftime_mes_es($hoy))) ?></span>
      </h3>
      <?php if ($pred['ok']): ?>
        <button type="button" class="dash-card-link" id="pred_open">Ver más →</button>
      <?php endif; ?>
    </header>


    <!-- Estado: sin datos / mes pasado -->
    <div id="pred_no_disponible" <?= $pred['ok'] ? 'hidden' : '' ?>>
      <p class="text-muted" id="pred_no_disponible_msg"><?= e($pred['razon']) ?></p>
      <p class="text-soft" style="font-size: var(--fs-xs); margin-top: 6px;">
        Necesitas: cuestionario inicial + peso registrado + una dieta asignada.
      </p>
    </div>

    <!-- Estado: predicción disponible -->
    <div id="pred_disponible" <?= $pred['ok'] ? '' : 'hidden' ?>>
      <?php
        $kgDia = (float)($pred['kg_dia'] ?? 0);
        $rumbo = $kgDia > 0.001 ? 'down' : ($kgDia < -0.001 ? 'up' : 'flat');
        $verbo = $rumbo === 'down' ? 'Vas a perder' : ($rumbo === 'up' ? 'Vas a ganar' : 'Mantendrás peso');
      ?>
      <p class="pred-resumen">
        <strong class="pred-resumen-strong pred-rumbo--<?= e($rumbo) ?>" id="pred_resumen_verbo"><?= e($verbo) ?></strong>
        a este ritmo:
      </p>
      <ul class="pred-stats" role="list">
        <li class="pred-stat pred-stat--<?= e($rumbo) ?>" data-pill="dia">
          <span class="pred-stat-num" id="pred_stat_dia"><?= fmt_g_dia($kgDia) ?></span>
          <span class="pred-stat-lbl">Día</span>
        </li>
        <li class="pred-stat pred-stat--<?= e($rumbo) ?>" data-pill="sem">
          <span class="pred-stat-num" id="pred_stat_sem"><?= fmt_kg($pred['kg_semana'] ?? null) ?></span>
          <span class="pred-stat-lbl">Semana</span>
        </li>
        <li class="pred-stat pred-stat--<?= e($rumbo) ?>" data-pill="mes">
          <span class="pred-stat-num" id="pred_stat_mes"><?= fmt_kg($pred['kg_mes'] ?? null) ?></span>
          <span class="pred-stat-lbl">Mes</span>
        </li>
      </ul>
      <p class="pred-foot" id="pred_foot">
        TDEE <span id="pred_foot_tdee"><?= (int)($pred['tdee_pond'] ?? 0) ?></span> kcal ·
        Kcal media dieta <span id="pred_foot_kcal"><?= (int)($pred['kcal_media'] ?? 0) ?></span> ·
        <span id="pred_foot_dias"><?= (int)($pred['dias_con_dieta'] ?? $pred['dias_con_dieta_30'] ?? 0) ?>/<?= (int)($pred['dias_ventana'] ?? 30) ?> días asignados</span>
      </p>
    </div>
  </article>

  <!-- TARJETA 1: CALENDARIO DEL MES -->
  <article class="card cal-card cal-card--dashboard">
    <header class="dash-card-header">
      <h3 class="card-title">📆 Tu calendario</h3>
      <a class="dash-card-link" href="cliente_dieta.php">Ver mi dieta →</a>
    </header>

    <nav class="cal-nav" aria-label="Mes">
      <button type="button" class="day-nav-btn" id="calPrev" aria-label="Mes anterior">←</button>
      <button type="button" class="day-nav-current cal-month-btn" id="calMonthLabel" aria-label="Volver a hoy" title="Click: volver a hoy">—</button>
      <button type="button" class="day-nav-btn" id="calNext" aria-label="Mes siguiente">→</button>
    </nav>

    <div class="cal-grid" id="calGrid" role="grid">
      <div class="cal-dow">L</div><div class="cal-dow">M</div><div class="cal-dow">X</div>
      <div class="cal-dow">J</div><div class="cal-dow">V</div><div class="cal-dow">S</div><div class="cal-dow">D</div>
    </div>

    <p class="text-muted cal-foot-hint">💡 Toca un día para ver la dieta asignada.</p>
  </article>

  <!-- TARJETA: dieta de hoy -->
  <article class="card dash-card">
    <header class="dash-card-header">
      <h3 class="card-title">🍽️ Tu dieta de hoy</h3>
      <a class="dash-card-link" href="cliente_dieta.php">Ver</a>
    </header>

    <?php if ($dietaHoy): ?>
      <div class="dash-diet">
        <span class="dash-diet-icon" aria-hidden="true"><?= e($dietaHoy['icono'] ?: '🍽️') ?></span>
        <span class="dash-diet-name"><?= e($dietaHoy['nombre']) ?></span>
      </div>
      <ul class="dash-macros" role="list">
        <li><span class="m-num"><?= (int)$dietaHoy['kcal_objetivo'] ?></span><span class="m-lbl">kcal</span></li>
        <li><span class="m-num"><?= (int)$dietaHoy['prot_objetivo'] ?>g</span><span class="m-lbl">Proteínas</span></li>
        <li><span class="m-num"><?= (int)$dietaHoy['carb_objetivo'] ?>g</span><span class="m-lbl">Carbos</span></li>
        <li><span class="m-num"><?= (int)$dietaHoy['grasas_objetivo'] ?>g</span><span class="m-lbl">Grasas</span></li>
      </ul>
    <?php else: ?>
      <p class="text-muted">No tienes dieta asignada para hoy.</p>
    <?php endif; ?>
  </article>

  <!-- TARJETA: check-in semanal -->
  <article class="card dash-card">
    <header class="dash-card-header">
      <h3 class="card-title">📝 Check-in semanal</h3>
      <a class="dash-card-link" href="cliente_checkin.php"><?= $checkinSemana ? 'Editar' : 'Hacer' ?></a>
    </header>

    <?php if ($checkinSemana): ?>
      <p class="text-soft">Ya completaste el check-in de esta semana. Resumen:</p>
      <ul class="dash-checkin-resumen" role="list">
        <li><span>Hambre</span><strong><?= (int)$checkinSemana['hambre'] ?>/5</strong></li>
        <li><span>Energía</span><strong><?= (int)$checkinSemana['energia'] ?>/5</strong></li>
        <li><span>Sueño</span><strong><?= (int)$checkinSemana['sueno'] ?>/5</strong></li>
        <li><span>Dieta</span><strong><?= (int)$checkinSemana['cumplimiento_dieta'] ?>/5</strong></li>
        <li><span>Ánimo</span><strong><?= (int)$checkinSemana['animo'] ?>/5</strong></li>
      </ul>
    <?php else: ?>
      <p class="text-soft">Aún no has hecho el check-in de esta semana. Solo te llevará un minuto.</p>
      <a class="btn btn-primary btn-block" href="cliente_checkin.php">Hacer check-in</a>
    <?php endif; ?>
  </article>

  <!-- TARJETA: próxima consulta -->
  <article class="card dash-card">
    <header class="dash-card-header">
      <h3 class="card-title">📅 Próxima consulta</h3>
      <a class="dash-card-link" href="cliente_consultas.php">Ver todas</a>
    </header>

    <?php if ($proximaConsulta): ?>
      <p class="dash-consulta-fecha">
        <?= e(date('d/m/Y · H:i', strtotime($proximaConsulta['fecha']))) ?>
      </p>
      <p class="text-soft">
        Con <?= e($proximaConsulta['dietista']) ?> · <?= e(ucfirst($proximaConsulta['tipo'])) ?>
      </p>
    <?php else: ?>
      <p class="text-muted">No tienes consultas programadas.</p>
    <?php endif; ?>
  </article>

  <!-- TARJETA: última medida -->
  <article class="card dash-card">
    <header class="dash-card-header">
      <h3 class="card-title">📏 Última medida</h3>
      <a class="dash-card-link" href="cliente_medidas.php">Ver todas</a>
    </header>

    <?php if ($ultimaMedida): ?>
      <p class="text-soft">Registrada el <?= e(date('d/m/Y', strtotime($ultimaMedida['fecha']))) ?></p>
      <ul class="dash-mini-stats" role="list">
        <?php if ($ultimaMedida['cintura']): ?><li>Cintura: <strong><?= e($ultimaMedida['cintura']) ?> cm</strong></li><?php endif; ?>
        <?php if ($ultimaMedida['cadera']):  ?><li>Cadera: <strong><?= e($ultimaMedida['cadera']) ?> cm</strong></li><?php endif; ?>
        <?php if ($ultimaMedida['pecho']):   ?><li>Pecho: <strong><?= e($ultimaMedida['pecho']) ?> cm</strong></li><?php endif; ?>
      </ul>
    <?php else: ?>
      <p class="text-muted">Aún no has registrado medidas.</p>
      <a class="btn btn-outline btn-block" href="cliente_medidas.php">Añadir primera medida</a>
    <?php endif; ?>
  </article>

  <!-- Accesos rápidos -->
  <section class="dash-quick">
    <a class="dash-quick-btn" href="cliente_progresos.php"><span>📈</span><span>Progresos</span></a>
    <a class="dash-quick-btn" href="cliente_fotos.php"><span>📸</span><span>Fotos</span></a>
    <a class="dash-quick-btn" href="cliente_objetivos.php"><span>🎯</span><span>Objetivos</span></a>
    <a class="dash-quick-btn" href="../../mensajes.php"><span>💬</span><span>Chat</span></a>
  </section>

</main>

<!-- Modal "Ver más" de predicciones -->
<?php if ($pred['ok']): ?>
<div id="predModal" class="modal-backdrop" hidden>
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="predModalTitle">
    <div class="modal-head">
      <h3 class="modal-title" id="predModalTitle">🔮 Tus predicciones detalladas</h3>
      <button type="button" class="modal-close" id="predModalClose" aria-label="Cerrar">✕</button>
    </div>
    <div class="modal-body">
      <?php
        $kgDia = (float)$pred['kg_dia'];
        $rumbo = $kgDia > 0.001 ? 'down' : ($kgDia < -0.001 ? 'up' : 'flat');
        $absDef = abs((int)$pred['deficit_dia']);
        $tipoStr = $kgDia > 0.001 ? 'déficit' : ($kgDia < -0.001 ? 'superávit' : 'mantenimiento');
        $iconRumbo = $rumbo === 'down' ? '📉' : ($rumbo === 'up' ? '📈' : '➖');
      ?>

      <p class="pred-detail-intro">
        Hemos calculado lo que <strong>perderás (o ganarás) si mantienes la dieta actual</strong>,
        a partir de tu gasto calórico estimado (TDEE) y las kcal medias de tu dieta asignada.
      </p>

      <!-- Resumen grande -->
      <div class="pred-big-row">
        <div class="pred-big pred-big--<?= e($rumbo) ?>">
          <span class="pred-big-icon"><?= $iconRumbo ?></span>
          <span class="pred-big-num"><?= fmt_kg($pred['kg_mes'], 1) ?></span>
          <span class="pred-big-lbl">en un mes</span>
        </div>
      </div>

      <!-- Cálculo paso a paso -->
      <section class="pred-detail-section">
        <h4 class="pred-detail-title">📊 Cómo se calcula</h4>
        <ul class="pred-detail-list">
          <li><span>Tu gasto calórico ponderado (TDEE)</span><strong><?= (int)$pred['tdee_pond'] ?> kcal/día</strong></li>
          <li><span>· Día de entreno</span><strong><?= (int)$pred['tdee_ent'] ?> kcal</strong></li>
          <li><span>· Día de descanso</span><strong><?= (int)$pred['tdee_desc'] ?> kcal</strong></li>
          <li><span>· Días de gym/semana</span><strong><?= (int)$pred['dias_gym'] ?></strong></li>
          <li class="pred-sep"><span>Kcal media de tu dieta</span><strong><?= (int)$pred['kcal_media'] ?> kcal/día</strong></li>
          <li><span><?= ucfirst($tipoStr) ?> diario</span><strong class="pred-rumbo--<?= e($rumbo) ?>"><?= $absDef ?> kcal</strong></li>
        </ul>
      </section>

      <!-- Tabla de proyección -->
      <section class="pred-detail-section">
        <h4 class="pred-detail-title">📅 Proyección</h4>
        <ul class="pred-detail-list">
          <li><span>Al día (promedio real)</span><strong class="pred-rumbo--<?= e($rumbo) ?>"><?= fmt_g_dia($pred['kg_dia']) ?></strong></li>
          <li><span>Próximos 7 días</span><strong class="pred-rumbo--<?= e($rumbo) ?>"><?= fmt_kg($pred['kg_semana']) ?></strong></li>
          <li><span>Próximos 30 días</span><strong class="pred-rumbo--<?= e($rumbo) ?>"><?= fmt_kg($pred['kg_mes']) ?></strong></li>
          <li><span>A 3 meses (extrapolación)</span><strong class="pred-rumbo--<?= e($rumbo) ?>"><?= fmt_kg($pred['kg_mes'] * 3, 1) ?></strong></li>
          <li><span>A 6 meses (extrapolación)</span><strong class="pred-rumbo--<?= e($rumbo) ?>"><?= fmt_kg($pred['kg_mes'] * 6, 1) ?></strong></li>
        </ul>
      </section>

      <!-- Transparencia del cálculo -->
      <section class="pred-detail-section">
        <h4 class="pred-detail-title">🔍 Transparencia del cálculo</h4>
        <ul class="pred-detail-list">
          <li><span>Próximos 7 días con dieta asignada</span><strong><?= (int)$pred['dias_con_dieta_7'] ?> / 7</strong></li>
          <li><span>Próximos 30 días con dieta asignada</span><strong><?= (int)$pred['dias_con_dieta_30'] ?> / 30</strong></li>
          <li><span>Días vacíos (sin dieta) en 30 días</span><strong><?= (int)$pred['dias_vacios_30'] ?></strong></li>
          <?php if ((int)$pred['dias_ambiguos_30'] > 0): ?>
            <li><span>⚠️ Días con MÚLTIPLES dietas asignadas</span><strong class="pred-rumbo--up"><?= (int)$pred['dias_ambiguos_30'] ?></strong></li>
          <?php endif; ?>
          <li><span>Balance total próximos 30 días</span><strong><?= number_format((int)$pred['balance_mes'], 0, ',', '.') ?> kcal</strong></li>
        </ul>
        <p class="pred-disclaimer">
          Los días vacíos se computan como <strong>mantenimiento (balance 0)</strong>. Esto significa que tu proyección refleja exactamente lo que está en tu calendario.
          <?php if ($pred['usado_fallback']): ?>
            <br>📌 Como no tienes calendario asignado, se ha proyectado con tu última dieta aplicada todos los días.
          <?php endif; ?>
        </p>
        <?php if ((int)$pred['dias_ambiguos_30'] > 0): ?>
          <div class="alert alert-warning" style="margin-top: var(--sp-2);">
            ⚠️ Hay días con más de una dieta asignada. Para esos días se ha usado el promedio. Avisa a tu dietista para que revise el calendario.
          </div>
        <?php endif; ?>
      </section>

      <!-- Desglose por dieta -->
      <?php if (!empty($pred['dietas_breakdown'])): ?>
      <section class="pred-detail-section">
        <h4 class="pred-detail-title">🍽️ Dietas en tu calendario (próximos 30 días)</h4>
        <ul class="pred-diet-list">
          <?php foreach ($pred['dietas_breakdown'] as $d): ?>
            <li class="pred-diet-item">
              <span class="pred-diet-icon" style="background: <?= e($d['color'] ?? '#2F9E73') ?>;"><?= e($d['icono'] ?? '🍽️') ?></span>
              <div class="pred-diet-info">
                <strong><?= e($d['nombre']) ?></strong>
                <span class="text-muted"><?= (int)$d['kcal_objetivo'] ?> kcal · <?= (int)$d['dias'] ?> día<?= $d['dias'] == 1 ? '' : 's' ?></span>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>

      <!-- Aviso si déficit excesivo -->
      <?php if ($pred['ok'] && $absDef > 1000 && $kgDia > 0): ?>
        <div class="alert alert-warning" style="margin-top: var(--sp-3);">
          ⚠️ Tu déficit es alto (<?= $absDef ?> kcal/día). Aunque parezca rápido, déficits muy agresivos pueden frenarse a las pocas semanas. Comenta con tu dietista si lo notas.
        </div>
      <?php elseif ($pred['ok'] && $kgDia < -0.1): ?>
        <div class="alert alert-info" style="margin-top: var(--sp-3);">
          ℹ️ Estás en superávit calórico. Si tu objetivo es ganar masa muscular, perfecto. Si quieres bajar, revisa con tu dietista.
        </div>
      <?php endif; ?>

      <p class="pred-disclaimer">
        ⚠️ Estas son <strong>estimaciones teóricas</strong>. La realidad varía: tu adherencia, retención de líquidos,
        cambios hormonales y exactitud de la báscula influyen. Considera estos números como una <em>guía</em>, no una garantía.
      </p>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Modal detalle día del calendario -->
<div id="modalDia" class="modal-backdrop" hidden>
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modalDiaTitle">
    <div class="modal-head">
      <h3 class="modal-title" id="modalDiaTitle">📌 —</h3>
      <button type="button" class="modal-close" id="modalDiaClose" aria-label="Cerrar">✕</button>
    </div>
    <div class="modal-body" id="modalDiaBody"></div>
  </div>
</div>

<script>
window.CAL_DATA = {
  mode:        'cliente',
  csrf:        <?= json_encode(csrf_token()) ?>,
  id_cliente:  <?= (int)$uid ?>,
  mes_inicial: <?= json_encode(date('Y-m')) ?>,
  base_url:    <?= json_encode($base) ?>
};

// Modal de predicciones
(function(){
  var modal = document.getElementById('predModal');
  var openBtn = document.getElementById('pred_open');
  var closeBtn = document.getElementById('predModalClose');
  if (!modal || !openBtn) return;
  function abrir(){ modal.hidden = false; document.body.style.overflow = 'hidden'; }
  function cerrar(){ modal.hidden = true; document.body.style.overflow = ''; }
  openBtn.addEventListener('click', abrir);
  if (closeBtn) closeBtn.addEventListener('click', cerrar);
  modal.addEventListener('click', function(e){ if (e.target === modal) cerrar(); });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && !modal.hidden) cerrar(); });
})();

// ============================================================
// PREDICCIONES DINÁMICAS · MutationObserver del calendario
// Versión con debug visible en la tarjeta + URL absoluta + autodetect
// ============================================================
(function(){
  // Construir endpoint relativo (sirve igual) basado en location.pathname
  // El dashboard está en /.../roles/cliente/cliente_dashboard.php → subir 2
  var pathname = location.pathname;
  var raiz = pathname.replace(/\/roles\/cliente\/[^\/]+$/, '/');
  if (raiz === pathname) raiz = pathname.replace(/[^\/]+$/, '');  // fallback
  var ENDPOINT_RAIZ = location.origin + raiz + 'ajax_predicciones.php';
  var ENDPOINT_AJAX = location.origin + raiz + 'ajax/ajax_predicciones.php';
  var ENDPOINT_ACTIVO = null;  // se decide en el primer fetch
  var MES_INICIAL = <?= json_encode(date('Y-m')) ?>;

  var elCard      = document.getElementById('pred_card');
  var elTitulo    = document.getElementById('pred_mes_lbl');
  var elDisp      = document.getElementById('pred_disponible');
  var elNoDisp    = document.getElementById('pred_no_disponible');
  var elNoDispMsg = document.getElementById('pred_no_disponible_msg');
  var elVerbo     = document.getElementById('pred_resumen_verbo');
  var elNumDia    = document.getElementById('pred_stat_dia');
  var elNumSem    = document.getElementById('pred_stat_sem');
  var elNumMes    = document.getElementById('pred_stat_mes');
  var elFootTdee  = document.getElementById('pred_foot_tdee');
  var elFootKcal  = document.getElementById('pred_foot_kcal');
  var elFootDias  = document.getElementById('pred_foot_dias');
  var elCalLabel  = document.getElementById('calMonthLabel');
  var elDebug     = document.getElementById('pred_debug');
  var elDebugMsg  = document.getElementById('pred_debug_msg');
  var pillDia, pillSem, pillMes;
  if (elCard) {
    pillDia = elCard.querySelector('[data-pill="dia"]');
    pillSem = elCard.querySelector('[data-pill="sem"]');
    pillMes = elCard.querySelector('[data-pill="mes"]');
  }

  // Helper: log a consola Y a la tarjeta
  function dbg(msg, isError){
    var pref = '[PRED] ' + msg;
    if (isError) console.error(pref); else console.log(pref);
    if (elDebug && elDebugMsg) {
      elDebug.hidden = false;
      elDebugMsg.textContent = msg;
      elDebug.style.background = isError ? '#FBE6E6' : '#E6F4ED';
      elDebug.style.color      = isError ? '#8A2424' : '#1F6A4D';
      elDebug.style.borderLeftColor = isError ? '#D24A4A' : '#2F9E73';
    }
  }
  function dbgWarn(msg){
    console.warn('[PRED] ' + msg);
    if (elDebug && elDebugMsg) {
      elDebug.hidden = false;
      elDebugMsg.textContent = msg;
      elDebug.style.background = '#FFF7E6';
      elDebug.style.color      = '#8A6510';
      elDebug.style.borderLeftColor = '#E0A21A';
    }
  }
  function dbgOk(){
    if (elDebug) {
      // Después de un éxito, ocultar tras 2 segundos
      setTimeout(function(){ elDebug.hidden = true; }, 2000);
    }
  }

  if (!elCard) { console.warn('[PRED] No existe #pred_card'); return; }
  if (!elCalLabel) {
    dbgWarn('No existe #calMonthLabel → no puedo observar el calendario');
    return;
  }

  dbg('Iniciado. ENDPOINT raiz=' + ENDPOINT_RAIZ);

  function fmtKg(v, dec){
    if (v === null || v === undefined || isNaN(v)) return '—';
    var abs = Math.abs(v);
    if (abs < 0.005) return '0 kg';
    var s = v < 0 ? '+' : '−';
    return s + abs.toFixed(dec === undefined ? 2 : dec).replace('.', ',') + ' kg';
  }
  function fmtGDia(v){
    if (v === null || v === undefined || isNaN(v)) return '—';
    var g = v * 1000;
    var abs = Math.abs(g);
    if (abs < 0.5) return '0 g';
    var s = v < 0 ? '+' : '−';
    return s + Math.round(abs).toLocaleString('es-ES') + ' g';
  }
  function setRumboClass(rumbo){
    [pillDia, pillSem, pillMes].forEach(function(el){
      if (!el) return;
      el.classList.remove('pred-stat--down', 'pred-stat--up', 'pred-stat--flat');
      el.classList.add('pred-stat--' + rumbo);
    });
    if (elVerbo) {
      elVerbo.classList.remove('pred-rumbo--down', 'pred-rumbo--up', 'pred-rumbo--flat');
      elVerbo.classList.add('pred-rumbo--' + rumbo);
    }
  }
  function nombreMesES(mesStr){
    var meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    var p = mesStr.split('-');
    var m = parseInt(p[1], 10);
    return meses[m - 1].charAt(0).toUpperCase() + meses[m - 1].slice(1) + ' ' + p[0];
  }
  function parseLabelMes(txt){
    if (!txt) return null;
    var meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    var t = txt.trim().toLowerCase().replace(/\s+/g, ' ');
    var partes = t.split(' ');
    if (partes.length < 2) return null;
    var nombre = partes[0];
    var anio   = parseInt(partes[partes.length - 1], 10);
    var idx    = meses.indexOf(nombre);
    if (idx < 0 || isNaN(anio)) return null;
    return anio + '-' + String(idx + 1).padStart(2, '0');
  }
  function mostrarNoDisponible(msg, mesNombre){
    if (elTitulo) elTitulo.textContent = mesNombre || '—';
    if (elDisp)   elDisp.hidden   = true;
    if (elNoDisp) elNoDisp.hidden = false;
    if (elNoDispMsg) elNoDispMsg.textContent = msg || 'Predicción no disponible.';
  }
  function mostrarDatos(j){
    var kgDia = parseFloat(j.kg_dia) || 0;
    var rumbo = kgDia > 0.001 ? 'down' : (kgDia < -0.001 ? 'up' : 'flat');
    var verbo = rumbo === 'down' ? 'Vas a perder' : (rumbo === 'up' ? 'Vas a ganar' : 'Mantendrás peso');

    if (elTitulo) elTitulo.textContent = j.mes_nombre.charAt(0).toUpperCase() + j.mes_nombre.slice(1);
    if (elNoDisp) elNoDisp.hidden = true;
    if (elDisp)   elDisp.hidden   = false;

    if (elVerbo)   elVerbo.textContent   = verbo;
    if (elNumDia)  elNumDia.textContent  = fmtGDia(kgDia);
    if (elNumSem)  elNumSem.textContent  = fmtKg(j.kg_semana);
    if (elNumMes)  elNumMes.textContent  = fmtKg(j.kg_mes);
    if (elFootTdee) elFootTdee.textContent = j.tdee_pond || 0;
    if (elFootKcal) elFootKcal.textContent = j.kcal_media || 0;
    if (elFootDias) elFootDias.textContent = (j.dias_con_dieta || 0) + '/' + (j.dias_ventana || 0) + ' días asignados';
    setRumboClass(rumbo);
  }

  // Hacer fetch probando primero ENDPOINT_ACTIVO o las dos rutas posibles
  async function hacerFetch(url){
    var res = await fetch(url, {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'Accept': 'application/json' }
    });
    return res;
  }
  async function fetchConFallback(mes){
    var qs = '?mes=' + encodeURIComponent(mes);
    // Si ya sabemos cuál funciona, ir directos
    if (ENDPOINT_ACTIVO) {
      return await hacerFetch(ENDPOINT_ACTIVO + qs);
    }
    // Probar raíz primero
    try {
      var r = await hacerFetch(ENDPOINT_RAIZ + qs);
      if (r.ok || r.status === 200) { ENDPOINT_ACTIVO = ENDPOINT_RAIZ; return r; }
      // 404 → probar /ajax/
      if (r.status === 404) {
        var r2 = await hacerFetch(ENDPOINT_AJAX + qs);
        if (r2.ok) { ENDPOINT_ACTIVO = ENDPOINT_AJAX; return r2; }
        return r2;
      }
      // 403/500 etc → devolver tal cual, no probar fallback
      ENDPOINT_ACTIVO = ENDPOINT_RAIZ;
      return r;
    } catch (e) {
      // Network error → probar /ajax/
      var r3 = await hacerFetch(ENDPOINT_AJAX + qs);
      if (r3.ok) { ENDPOINT_ACTIVO = ENDPOINT_AJAX; }
      return r3;
    }
  }

  var ultimoMesPedido = null;
  async function cargarMes(mes){
    if (!mes) return;
    if (mes === ultimoMesPedido) { return; }
    ultimoMesPedido = mes;
    dbg('Pidiendo mes ' + mes + ' …');

    try {
      var res = await fetchConFallback(mes);
      if (!res.ok) {
        dbg('HTTP ' + res.status + ' en ' + (ENDPOINT_ACTIVO || '?'), true);
        return;
      }
      var txt = await res.text();
      var j;
      try { j = JSON.parse(txt); }
      catch (e) {
        dbg('Respuesta no es JSON: ' + txt.substring(0, 100), true);
        return;
      }
      if (!j) { dbg('Respuesta vacía', true); return; }
      if (j.es_pasado) {
        mostrarNoDisponible('Este mes ya ha pasado · predicción no aplicable.', nombreMesES(mes));
        dbg('Mes ' + mes + ' es pasado · OK');
        dbgOk();
        return;
      }
      if (!j.ok) {
        mostrarNoDisponible(j.razon || 'Predicción no disponible.', j.mes_nombre ? (j.mes_nombre.charAt(0).toUpperCase()+j.mes_nombre.slice(1)) : nombreMesES(mes));
        dbg('Mes ' + mes + ' sin datos: ' + (j.razon || ''));
        dbgOk();
        return;
      }
      mostrarDatos(j);
      dbg('OK · mes ' + mes + ' actualizado (kg_mes=' + j.kg_mes + ')');
      dbgOk();
    } catch (err) {
      dbg('Error fetch: ' + err.message, true);
    }
  }

  // 1) MutationObserver del label del calendario
  function leerYActualizar(){
    var txt = elCalLabel.textContent.trim();
    if (!txt || txt === '—') return;
    var mes = parseLabelMes(txt);
    if (!mes) {
      dbgWarn('No pude parsear el label: "' + txt + '"');
      return;
    }
    cargarMes(mes);
  }
  var observer = new MutationObserver(function(){ leerYActualizar(); });
  observer.observe(elCalLabel, { childList: true, characterData: true, subtree: true });

  // 2) Evento custom (si calendario.js está parcheado)
  document.addEventListener('calendar:mes-cambiado', function(e){
    if (e && e.detail && e.detail.mes) cargarMes(e.detail.mes);
  });

  // 3) Primera lectura por si el label ya tenía contenido
  setTimeout(leerYActualizar, 500);
})();
</script>
<script src="<?= e($base) ?>js/calendario.js" defer></script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
<?php
// Helper local: nombre del día/mes en español sin depender de setlocale
function strftime_es(DateTime $d): string {
    $dias  = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
    $meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    return $dias[(int)$d->format('w')] . ', ' . (int)$d->format('j') . ' de ' . $meses[(int)$d->format('n') - 1];
}
// Helper: "Mayo 2026"
function strftime_mes_es(DateTime $d): string {
    $meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    return $meses[(int)$d->format('n') - 1] . ' ' . $d->format('Y');
}
?>