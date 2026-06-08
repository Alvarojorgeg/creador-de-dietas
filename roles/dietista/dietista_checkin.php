<?php
require_once __DIR__ . '/../../includes/conexion.php';
requiere_rol('dietista', '../../');

// ============================================================
// LÓGICA DE PUNTUACIÓN (definida ARRIBA para que esté disponible)
// ============================================================
//
// Convención: 1 = malo, 5 = bueno (en TODAS las métricas).
// El hambre se invierte porque si el cliente reporta hambre 5
// significa que pasa mucha hambre (la dieta es demasiado restrictiva).
//
//   hambre:        1=saciado (bien), 5=mucha hambre (mal)  → invertimos
//   energia:       1=cansado (mal),  5=lleno energía (bien)
//   sueno:         1=mal sueño,      5=duerme bien
//   cumplimiento:  1=no cumple,      5=cumple a tope
//   animo:         1=bajo,           5=feliz
//
function bienestar_score(array $ck): float {
    $hambreInv = 6 - (int)$ck['hambre'];
    $sum = $hambreInv
         + (int)$ck['energia']
         + (int)$ck['sueno']
         + (int)$ck['cumplimiento_dieta']
         + (int)$ck['animo'];
    return round($sum / 5, 2);
}
function semaforo_clase(float $score): string {
    if ($score >= 4.0)  return 'ck-row--ok';
    if ($score >= 2.5)  return 'ck-row--warn';
    return 'ck-row--bad';
}
// Cada métrica: ¿es buena o mala según su valor?
// devuelve 'good' | 'warn' | 'bad' considerando la dirección.
function color_metrica(string $tipo, int $v): string {
    if ($tipo === 'hambre') {
        // 5 = mucha hambre = malo
        if ($v <= 2) return 'good';
        if ($v <= 3) return 'warn';
        return 'bad';
    }
    // resto: 5 = bueno
    if ($v >= 4) return 'good';
    if ($v >= 3) return 'warn';
    return 'bad';
}

// ============================================================
$uid = usuario_id();

$hoy   = new DateTime('today');
$dow   = (int)$hoy->format('N');
$lunes = (clone $hoy)->modify('-' . ($dow - 1) . ' days');
$lunesStr = $lunes->format('Y-m-d');
$domStr   = (clone $lunes)->modify('+6 days')->format('Y-m-d');

$idC = isset($_GET['id_cliente']) ? (int)$_GET['id_cliente'] : 0;

// ============================================================
// MODO LISTA (todos los clientes esta semana)
// ============================================================
if ($idC === 0) {
    // Trae el check-in de esta semana y el de la anterior por cliente
    $semanaAnt = (clone $lunes)->modify('-7 days')->format('Y-m-d');

    $stmt = $conn->prepare(
        "SELECT u.id, u.nombre_completo,
                ck.id AS ckin_id, ck.hambre, ck.energia, ck.sueno, ck.cumplimiento_dieta, ck.animo, ck.observaciones,
                ck2.hambre AS h2, ck2.energia AS e2, ck2.sueno AS s2, ck2.cumplimiento_dieta AS d2, ck2.animo AS a2
         FROM usuarios u
         LEFT JOIN checkins_semanales ck  ON ck.id_cliente  = u.id AND ck.semana_inicio  = ?
         LEFT JOIN checkins_semanales ck2 ON ck2.id_cliente = u.id AND ck2.semana_inicio = ?
         WHERE u.rol='cliente' AND u.id_dietista=? AND u.activo=1
         ORDER BY (ck.id IS NULL) DESC, u.nombre_completo ASC"
    );
    $stmt->bind_param('ssi', $lunesStr, $semanaAnt, $uid);
    $stmt->execute();
    $estado = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Resumen del grupo
    $totalCli = count($estado);
    $hechos   = 0; $sumBien = 0;
    foreach ($estado as $r) {
        if (!empty($r['ckin_id'])) { $hechos++; $sumBien += bienestar_score($r); }
    }
    $mediaGrupo = $hechos > 0 ? round($sumBien / $hechos, 2) : null;

    $base   = '../../';
    $active = 'checkin';
    $titulo = 'Check-ins de la semana';
    include __DIR__ . '/../../includes/sidebar.php';
    ?>
    <main class="page">

      <header class="ck-header">
        <h2 class="h1">📝 Check-ins · Semana actual</h2>
        <p class="text-soft">
          Del <strong><?= e($lunes->format('d/m')) ?></strong> al
          <strong><?= e(date('d/m/Y', strtotime($domStr))) ?></strong>
        </p>
      </header>

      <?php if (!$estado): ?>
        <div class="card text-center">
          <p class="text-muted">No tienes clientes asignados.</p>
        </div>
      <?php else: ?>

        <!-- Resumen agregado -->
        <section class="card">
          <h3 class="card-title">📊 Resumen del grupo</h3>
          <div class="prog-stats">
            <div class="prog-stat">
              <span class="prog-stat-num"><?= $hechos ?>/<?= $totalCli ?></span>
              <span class="prog-stat-lbl">Han hecho check-in</span>
            </div>
            <div class="prog-stat">
              <span class="prog-stat-num"><?= $mediaGrupo !== null ? e((string)$mediaGrupo) : '—' ?></span>
              <span class="prog-stat-lbl">Bienestar medio · /5</span>
            </div>
            <div class="prog-stat">
              <span class="prog-stat-num"><?= $totalCli > 0 ? round(($hechos / $totalCli) * 100) : 0 ?>%</span>
              <span class="prog-stat-lbl">Adherencia esta semana</span>
            </div>
          </div>
          <p class="text-muted" style="font-size:11px;margin-top:var(--sp-2);">
            💡 <strong>Bienestar</strong>: media de las 5 métricas (1=mal, 5=bien). El hambre se invierte porque "mucha hambre" indica dieta demasiado restrictiva.
          </p>
        </section>

        <section class="card">
          <h3 class="card-title">👥 Por cliente</h3>
          <ul class="ck-week" role="list">
            <?php foreach ($estado as $r):
              $hecho = !empty($r['ckin_id']);
              $bien  = $hecho ? bienestar_score($r) : 0;
              $cls   = $hecho ? semaforo_clase($bien) : 'ck-row--pendiente';

              // Diff con semana anterior
              $diff = null;
              if ($hecho && $r['h2'] !== null) {
                  $prev = ['hambre'=>$r['h2'],'energia'=>$r['e2'],'sueno'=>$r['s2'],'cumplimiento_dieta'=>$r['d2'],'animo'=>$r['a2']];
                  $diff = round($bien - bienestar_score($prev), 2);
              }
            ?>
              <li class="ck-row <?= e($cls) ?>">
                <a class="ck-row-link" href="dietista_checkin.php?id_cliente=<?= (int)$r['id'] ?>">
                  <div class="chats-avatar">
                    <?= e(mb_strtoupper(mb_substr($r['nombre_completo'], 0, 1, 'UTF-8'), 'UTF-8')) ?>
                  </div>
                  <div class="ck-row-info">
                    <div class="ck-row-name"><?= e($r['nombre_completo']) ?></div>
                    <?php if ($hecho): ?>
                      <div class="ck-row-vals">
                        <span class="ck-m ck-m--<?= color_metrica('hambre', (int)$r['hambre']) ?>"><span class="ck-m-emoji">😋</span><span class="ck-m-label">Hambre</span><span class="ck-m-val"><?= (int)$r['hambre'] ?></span></span>
                        <span class="ck-m ck-m--<?= color_metrica('energia', (int)$r['energia']) ?>"><span class="ck-m-emoji">⚡</span><span class="ck-m-label">Energía</span><span class="ck-m-val"><?= (int)$r['energia'] ?></span></span>
                        <span class="ck-m ck-m--<?= color_metrica('sueno', (int)$r['sueno']) ?>"><span class="ck-m-emoji">😴</span><span class="ck-m-label">Sueño</span><span class="ck-m-val"><?= (int)$r['sueno'] ?></span></span>
                        <span class="ck-m ck-m--<?= color_metrica('dieta', (int)$r['cumplimiento_dieta']) ?>"><span class="ck-m-emoji">🍽️</span><span class="ck-m-label">Dieta</span><span class="ck-m-val"><?= (int)$r['cumplimiento_dieta'] ?></span></span>
                        <span class="ck-m ck-m--<?= color_metrica('animo', (int)$r['animo']) ?>"><span class="ck-m-emoji">😊</span><span class="ck-m-label">Ánimo</span><span class="ck-m-val"><?= (int)$r['animo'] ?></span></span>
                      </div>
                    <?php else: ?>
                      <div class="ck-row-pendiente">Pendiente esta semana</div>
                    <?php endif; ?>
                  </div>
                  <div class="ck-row-right">
                    <?php if ($hecho): ?>
                      <span class="ck-media"><?= e((string)$bien) ?>/5</span>
                      <?php if ($diff !== null): ?>
                        <span class="ck-diff <?= $diff > 0 ? 'is-up-good' : ($diff < 0 ? 'is-down-bad' : 'is-neutral') ?>">
                          <?= $diff > 0 ? '▲ +' : ($diff < 0 ? '▼ ' : '') ?><?= e((string)$diff) ?>
                        </span>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="ck-media ck-media--pendiente">—</span>
                    <?php endif; ?>
                  </div>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>

    </main>
    <?php
    include __DIR__ . '/../../includes/footer.php';
    exit;
}

// ============================================================
// MODO HISTÓRICO DE UN CLIENTE (?id_cliente=X)
// ============================================================
$stmt = $conn->prepare(
    "SELECT id, nombre_completo FROM usuarios
     WHERE id=? AND id_dietista=? AND rol='cliente' AND activo=1"
);
$stmt->bind_param('ii', $idC, $uid);
$stmt->execute();
$cliente = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$cliente) { header('Location: dietista_checkin.php'); exit; }

$stmt = $conn->prepare(
    "SELECT * FROM checkins_semanales
     WHERE id_cliente=? ORDER BY semana_inicio DESC LIMIT 24"
);
$stmt->bind_param('i', $idC);
$stmt->execute();
$historico = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Para gráfica (orden cronológico ascendente)
$chartLabels = [];
$chartBien   = [];
$chartHam    = [];
$chartEne    = [];
$chartSue    = [];
$chartDie    = [];
$chartAni    = [];
foreach (array_reverse($historico) as $h) {
    $chartLabels[] = date('d/m', strtotime($h['semana_inicio']));
    $chartBien[]   = bienestar_score($h);
    $chartHam[]    = (int)$h['hambre'];
    $chartEne[]    = (int)$h['energia'];
    $chartSue[]    = (int)$h['sueno'];
    $chartDie[]    = (int)$h['cumplimiento_dieta'];
    $chartAni[]    = (int)$h['animo'];
}

// Estadísticas globales
$mediaTotal = 0; $totalChecks = count($historico);
foreach ($historico as $h) $mediaTotal += bienestar_score($h);
$mediaTotal = $totalChecks > 0 ? round($mediaTotal / $totalChecks, 2) : null;

$bienActual = $historico ? bienestar_score($historico[0]) : null;
$bienAnt    = isset($historico[1]) ? bienestar_score($historico[1]) : null;
$diffActual = ($bienActual !== null && $bienAnt !== null) ? round($bienActual - $bienAnt, 2) : null;

$base   = '../../';
$active = 'checkin';
$titulo = $cliente['nombre_completo'];
include __DIR__ . '/../../includes/sidebar.php';
?>
<main class="page">

  <header class="ck-header">
    <p class="text-soft">Histórico de check-ins de</p>
    <h2 class="h1"><?= e($cliente['nombre_completo']) ?></h2>
    <div class="form-actions">
      <a class="btn btn-outline btn-mini" href="dietista_checkin.php">← Todos los clientes</a>
      <a class="btn btn-outline btn-mini" href="dietista_ficha.php?id=<?= (int)$cliente['id'] ?>">Ficha del cliente</a>
    </div>
  </header>

  <?php if (!$historico): ?>
    <div class="card text-center">
      <p class="text-muted">Este cliente aún no ha hecho check-ins.</p>
    </div>
  <?php else: ?>

    <!-- Resumen + comparación con semana anterior -->
    <section class="card">
      <h3 class="card-title">📊 Resumen</h3>
      <div class="prog-stats">
        <div class="prog-stat">
          <span class="prog-stat-num <?= $bienActual !== null && $bienActual >= 4 ? 'is-down' : ($bienActual !== null && $bienActual < 2.5 ? 'is-up' : '') ?>">
            <?= $bienActual !== null ? e((string)$bienActual) : '—' ?>
          </span>
          <span class="prog-stat-lbl">Bienestar actual /5</span>
        </div>
        <div class="prog-stat">
          <span class="prog-stat-num <?= $diffActual !== null && $diffActual > 0 ? 'is-down' : ($diffActual !== null && $diffActual < 0 ? 'is-up' : '') ?>">
            <?php if ($diffActual !== null) echo ($diffActual > 0 ? '+' : '') . $diffActual;
                  else echo '—'; ?>
          </span>
          <span class="prog-stat-lbl">vs semana anterior</span>
        </div>
        <div class="prog-stat">
          <span class="prog-stat-num"><?= $mediaTotal !== null ? e((string)$mediaTotal) : '—' ?></span>
          <span class="prog-stat-lbl">Media histórica</span>
        </div>
        <div class="prog-stat">
          <span class="prog-stat-num"><?= (int)$totalChecks ?></span>
          <span class="prog-stat-lbl">Check-ins totales</span>
        </div>
      </div>
    </section>

    <!-- Gráfica de bienestar -->
    <?php if ($totalChecks >= 2): ?>
      <section class="card">
        <header class="dash-card-header">
          <h3 class="card-title">📈 Evolución del bienestar</h3>
          <button type="button" class="btn btn-ghost btn-mini" id="btnToggleDetalle">Ver detalle por métrica</button>
        </header>
        <div class="ck-chart-wrap">
          <canvas id="ckChart" aria-label="Evolución de bienestar semanal"></canvas>
        </div>
        <p class="text-muted" style="font-size:11px;margin-top:var(--sp-2);">
          💡 Bienestar = (energía + sueño + dieta + ánimo + (6−hambre)) ÷ 5. Hambre alta = peor.
        </p>
      </section>
    <?php endif; ?>

    <!-- Histórico -->
    <section class="card">
      <h3 class="card-title">📅 <?= count($historico) ?> semana<?= count($historico) === 1 ? '' : 's' ?></h3>
      <ul class="ck-hist" role="list">
        <?php foreach ($historico as $i => $h):
          $ini  = new DateTime($h['semana_inicio']);
          $fin  = (clone $ini)->modify('+6 days');
          $bien = bienestar_score($h);

          $prev = $historico[$i + 1] ?? null;
          $diff = $prev !== null ? round($bien - bienestar_score($prev), 2) : null;
          $cls  = semaforo_clase($bien);
        ?>
          <li class="ck-hist-item ck-hist-item--<?= e($cls === 'ck-row--ok' ? 'ok' : ($cls === 'ck-row--bad' ? 'bad' : 'warn')) ?>">
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
              <li class="ck-m ck-m--<?= color_metrica('hambre', (int)$h['hambre']) ?>"><span class="ck-m-emoji">😋</span><span class="ck-m-label">Hambre</span><span class="ck-m-val"><?= (int)$h['hambre'] ?></span></li>
              <li class="ck-m ck-m--<?= color_metrica('energia', (int)$h['energia']) ?>"><span class="ck-m-emoji">⚡</span><span class="ck-m-label">Energía</span><span class="ck-m-val"><?= (int)$h['energia'] ?></span></li>
              <li class="ck-m ck-m--<?= color_metrica('sueno', (int)$h['sueno']) ?>"><span class="ck-m-emoji">😴</span><span class="ck-m-label">Sueño</span><span class="ck-m-val"><?= (int)$h['sueno'] ?></span></li>
              <li class="ck-m ck-m--<?= color_metrica('dieta', (int)$h['cumplimiento_dieta']) ?>"><span class="ck-m-emoji">🍽️</span><span class="ck-m-label">Dieta</span><span class="ck-m-val"><?= (int)$h['cumplimiento_dieta'] ?></span></li>
              <li class="ck-m ck-m--<?= color_metrica('animo', (int)$h['animo']) ?>"><span class="ck-m-emoji">😊</span><span class="ck-m-label">Ánimo</span><span class="ck-m-val"><?= (int)$h['animo'] ?></span></li>
            </ul>
            <?php if (!empty($h['observaciones'])): ?>
              <p class="ck-hist-obs">"<?= nl2br(e($h['observaciones'])) ?>"</p>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>

</main>

<?php if (!empty($historico) && count($historico) >= 2): ?>
<script>
window.CK_CHART = {
  labels: <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>,
  bienestar: <?= json_encode($chartBien) ?>,
  hambre:    <?= json_encode($chartHam) ?>,
  energia:   <?= json_encode($chartEne) ?>,
  sueno:     <?= json_encode($chartSue) ?>,
  dieta:     <?= json_encode($chartDie) ?>,
  animo:     <?= json_encode($chartAni) ?>
};
</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
  if (typeof Chart === 'undefined') return;
  const D = window.CK_CHART;
  const ctx = document.getElementById('ckChart');
  if (!ctx) return;

  let detalleVisible = false;

  function colorTheme() {
    return document.documentElement.getAttribute('data-theme') === 'dark'
      ? { grid:'#2F3E36', text:'#B0BDB5' }
      : { grid:'#E2E8E2', text:'#5A6660' };
  }

  function build() {
    const T = colorTheme();
    const datasets = [
      {
        label: 'Bienestar',
        data: D.bienestar,
        borderColor: '#2F9E73',
        backgroundColor: 'rgba(47,158,115,0.18)',
        borderWidth: 3,
        pointRadius: 4,
        tension: 0.3,
        fill: true
      }
    ];
    if (detalleVisible) {
      datasets.push(
        { label:'Hambre (inv)', data: D.hambre.map(v => 6 - v), borderColor:'#D24A4A', borderWidth:1.5, pointRadius:2, fill:false, borderDash:[4,3] },
        { label:'Energía',      data: D.energia, borderColor:'#F2A03D', borderWidth:1.5, pointRadius:2, fill:false, borderDash:[4,3] },
        { label:'Sueño',        data: D.sueno,   borderColor:'#3A86C7', borderWidth:1.5, pointRadius:2, fill:false, borderDash:[4,3] },
        { label:'Dieta',        data: D.dieta,   borderColor:'#8A5BD2', borderWidth:1.5, pointRadius:2, fill:false, borderDash:[4,3] },
        { label:'Ánimo',        data: D.animo,   borderColor:'#E091B6', borderWidth:1.5, pointRadius:2, fill:false, borderDash:[4,3] }
      );
    }
    return new Chart(ctx, {
      type: 'line',
      data: { labels: D.labels, datasets },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: detalleVisible, position: 'bottom', labels: { font:{size:11}, boxWidth:12, color: T.text } },
          tooltip: { callbacks: {} }
        },
        scales: {
          x: { grid: { color: T.grid }, ticks: { color: T.text, font:{size:10}, maxRotation:0, autoSkip:true, maxTicksLimit:10 } },
          y: { min: 0, max: 5, grid: { color: T.grid }, ticks: { color: T.text, font:{size:10}, stepSize: 1 } }
        }
      }
    });
  }

  let chart = build();
  const btn = document.getElementById('btnToggleDetalle');
  if (btn) btn.addEventListener('click', function(){
    detalleVisible = !detalleVisible;
    btn.textContent = detalleVisible ? 'Ocultar detalle' : 'Ver detalle por métrica';
    chart.destroy();
    chart = build();
  });
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
