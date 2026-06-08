<?php
require_once __DIR__ . '/../../includes/conexion.php';
requiere_rol('cliente', '../../');

$uid = usuario_id();

// --- Fecha seleccionada (?fecha=YYYY-MM-DD), por defecto hoy ---
$fechaIn = $_GET['fecha'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaIn)) $fechaIn = date('Y-m-d');
$fecha   = new DateTime($fechaIn);
$fechaStr = $fecha->format('Y-m-d');
$ayer    = (clone $fecha)->modify('-1 day')->format('Y-m-d');
$manana  = (clone $fecha)->modify('+1 day')->format('Y-m-d');

// --- Dieta(s) asignadas ese día ---
$stmt = $conn->prepare(
    "SELECT d.id, d.nombre, d.icono, d.color, d.kcal_objetivo,
            d.prot_objetivo, d.carb_objetivo, d.grasas_objetivo
     FROM calendario_asignaciones c
     JOIN dietas_base d ON d.id = c.id_dieta
     WHERE c.id_cliente = ? AND c.fecha_asignada = ?
     ORDER BY d.id ASC"
);
$stmt->bind_param('is', $uid, $fechaStr);
$stmt->execute();
$dietasDia = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// --- Dieta seleccionada (?dieta=ID) ---
$dietaActiva = null;
if ($dietasDia) {
    $idPedido = isset($_GET['dieta']) ? (int)$_GET['dieta'] : (int)$dietasDia[0]['id'];
    foreach ($dietasDia as $d) {
        if ((int)$d['id'] === $idPedido) { $dietaActiva = $d; break; }
    }
    if (!$dietaActiva) $dietaActiva = $dietasDia[0];
}

// --- Bloques + alimentos de la dieta activa ---
$bloques = [];
if ($dietaActiva) {
    $stmt = $conn->prepare(
        "SELECT b.id AS id_bloque, b.nombre_bloque, b.orden,
                da.id_alimento, da.cantidad_gr,
                a.nombre AS alimento, a.marca, a.racion_base_gr,
                a.kcal, a.proteinas, a.carbos, a.grasas
         FROM comidas_bloques b
         LEFT JOIN dieta_alimentos da ON da.id_bloque = b.id
         LEFT JOIN alimentos a ON a.id = da.id_alimento
         WHERE b.id_dieta = ?
         ORDER BY b.orden ASC, b.id ASC"
    );
    $idDieta = (int)$dietaActiva['id'];
    $stmt->bind_param('i', $idDieta);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $idb = (int)$r['id_bloque'];
        if (!isset($bloques[$idb])) {
            $bloques[$idb] = [
                'nombre'    => $r['nombre_bloque'],
                'orden'     => (int)$r['orden'],
                'alimentos' => [],
                'total'     => ['kcal'=>0,'p'=>0,'c'=>0,'g'=>0],
            ];
        }
        if ($r['id_alimento']) {
            $cant = (float)$r['cantidad_gr'];
            $base = max(1, (float)$r['racion_base_gr']);
            $factor = $cant / $base;
            $kcal = round($r['kcal'] * $factor);
            $p    = round($r['proteinas'] * $factor, 1);
            $c    = round($r['carbos']    * $factor, 1);
            $g    = round($r['grasas']    * $factor, 1);

            $bloques[$idb]['alimentos'][] = [
                'id_alimento' => (int)$r['id_alimento'],   // ← NUEVO: necesario para swaps
                'nombre'      => $r['alimento'],
                'marca'       => $r['marca'],
                'cantidad'    => $cant,
                'kcal'        => $kcal, 'p'=>$p, 'c'=>$c, 'g'=>$g,
            ];
            $bloques[$idb]['total']['kcal'] += $kcal;
            $bloques[$idb]['total']['p']    += $p;
            $bloques[$idb]['total']['c']    += $c;
            $bloques[$idb]['total']['g']    += $g;
        }
    }
    $stmt->close();
}

// --- Totales del día ---
$totalDia = ['kcal'=>0,'p'=>0,'c'=>0,'g'=>0];
foreach ($bloques as $b) {
    $totalDia['kcal'] += $b['total']['kcal'];
    $totalDia['p']    += $b['total']['p'];
    $totalDia['c']    += $b['total']['c'];
    $totalDia['g']    += $b['total']['g'];
}

$base   = '../../';
$active = 'dieta';
$titulo = 'Mi dieta';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main class="page">

  <!-- Navegación de días -->
  <nav class="day-nav" aria-label="Navegación entre días">
    <a class="day-nav-btn" href="?fecha=<?= e($ayer) ?>" aria-label="Día anterior">←</a>
    <div class="day-nav-current">
      <strong><?= e($fecha->format('d/m/Y')) ?></strong>
      <span class="text-muted"><?= e(dia_es($fecha)) ?></span>
    </div>
    <a class="day-nav-btn" href="?fecha=<?= e($manana) ?>" aria-label="Día siguiente">→</a>
  </nav>

  <!-- Selector si hay varias dietas asignadas -->
  <?php if (count($dietasDia) > 1): ?>
    <div class="diet-switcher" role="tablist" aria-label="Dietas asignadas">
      <?php foreach ($dietasDia as $d):
        $isActive = $dietaActiva && (int)$d['id'] === (int)$dietaActiva['id']; ?>
        <a class="diet-switcher-btn<?= $isActive ? ' is-active' : '' ?>"
           href="?fecha=<?= e($fechaStr) ?>&dieta=<?= (int)$d['id'] ?>"
           role="tab" aria-selected="<?= $isActive ? 'true' : 'false' ?>">
          <span aria-hidden="true"><?= e($d['icono'] ?: '🍽️') ?></span>
          <?= e($d['nombre']) ?>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!$dietaActiva): ?>
    <div class="card text-center">
      <p class="text-muted">No tienes ninguna dieta asignada para este día.</p>
    </div>
  <?php else: ?>

    <!-- Resumen de la dieta -->
    <article class="card diet-summary">
      <div class="diet-summary-head">
        <span class="dash-diet-icon" aria-hidden="true"><?= e($dietaActiva['icono'] ?: '🍽️') ?></span>
        <h2 class="diet-summary-name"><?= e($dietaActiva['nombre']) ?></h2>
      </div>

      <ul class="dash-macros" role="list">
        <li>
          <span class="m-num"><?= (int)$totalDia['kcal'] ?></span>
          <span class="m-lbl">kcal · obj <?= (int)$dietaActiva['kcal_objetivo'] ?></span>
        </li>
        <li>
          <span class="m-num"><?= (int)$totalDia['p'] ?>g</span>
          <span class="m-lbl">P · obj <?= (int)$dietaActiva['prot_objetivo'] ?>g</span>
        </li>
        <li>
          <span class="m-num"><?= (int)$totalDia['c'] ?>g</span>
          <span class="m-lbl">C · obj <?= (int)$dietaActiva['carb_objetivo'] ?>g</span>
        </li>
        <li>
          <span class="m-num"><?= (int)$totalDia['g'] ?>g</span>
          <span class="m-lbl">G · obj <?= (int)$dietaActiva['grasas_objetivo'] ?>g</span>
        </li>
      </ul>

      <div class="diet-actions">
        <button type="button" class="btn btn-outline btn-block" onclick="imprimirDieta(<?= (int)$dietaActiva['id'] ?>)">
        🖨️ Imprimir / PDF
      </button>
      </div>
    </article>

    <!-- Bloques de comidas -->
    <?php foreach ($bloques as $b): ?>
      <article class="card meal-block">
        <header class="meal-head">
          <h3 class="meal-name"><?= e($b['nombre']) ?></h3>
          <span class="meal-kcal"><?= (int)$b['total']['kcal'] ?> kcal</span>
        </header>

        <?php if (empty($b['alimentos'])): ?>
          <p class="text-muted">Sin alimentos en este bloque.</p>
        <?php else: ?>
          <ul class="meal-list" role="list">
            <?php foreach ($b['alimentos'] as $a): ?>
              <li class="meal-item"
                  data-id-alimento="<?= (int)$a['id_alimento'] ?>"
                  data-cantidad="<?= e((string)(int)$a['cantidad']) ?>">
                <div class="meal-item-info">
                  <span class="meal-item-name"><?= e($a['nombre']) ?></span>
                  <?php if (!empty($a['marca'])): ?>
                    <span class="meal-item-brand"><?= e($a['marca']) ?></span>
                  <?php endif; ?>
                  <span class="meal-item-qty"><?= e((string)(int)$a['cantidad']) ?> g</span>
                </div>
                <div class="meal-item-macros">
                  <span><?= (int)$a['kcal'] ?> kcal</span>
                  <span>P <?= e((string)$a['p']) ?></span>
                  <span>C <?= e((string)$a['c']) ?></span>
                  <span>G <?= e((string)$a['g']) ?></span>
                </div>
                <!-- el botón de "🔄 Alternativas" lo inyecta swaps.js -->
              </li>
            <?php endforeach; ?>
          </ul>

          <footer class="meal-foot">
            <span>P <?= e((string)$b['total']['p']) ?>g</span>
            <span>C <?= e((string)$b['total']['c']) ?>g</span>
            <span>G <?= e((string)$b['total']['g']) ?>g</span>
          </footer>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>

  <?php endif; ?>

  <a class="btn btn-outline btn-block" href="cliente_lista_compra.php">🛒 Lista de la compra</a>

</main>

<!-- ============================================================
     MODAL · Alternativas / swaps de alimentos
============================================================ -->
<div id="modalSwap" class="modal-backdrop" hidden>
  <div class="modal modal-wide" role="dialog" aria-modal="true" aria-labelledby="modalSwapTitle">
    <div class="modal-head">
      <h3 class="modal-title" id="modalSwapTitle">🔄 Alternativas</h3>
      <button type="button" class="modal-close" id="modalSwapClose" aria-label="Cerrar">✕</button>
    </div>
    <div class="modal-body" id="modalSwapBody">
      <p class="text-muted text-center">Cargando…</p>
    </div>
  </div>
</div>

<script src="<?= e($base) ?>js/swaps.js" defer></script>
<script>
function imprimirDieta(id) {
  // Eliminar iframe anterior si existe
  const old = document.getElementById('printFrame');
  if (old) old.remove();

  const iframe = document.createElement('iframe');
  iframe.id = 'printFrame';
  iframe.style.position = 'fixed';
  iframe.style.right = '-9999px';
  iframe.style.bottom = '-9999px';
  iframe.style.width = '0';
  iframe.style.height = '0';
  iframe.style.border = '0';
  iframe.src = 'cliente_imprimir_dieta.php?dieta=' + id + '&auto=1';
  document.body.appendChild(iframe);

  // Limpiar tras imprimir (10s)
  setTimeout(function(){ const f = document.getElementById('printFrame'); if (f) f.remove(); }, 10000);
}
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
<?php
function dia_es(DateTime $d): string {
    $dias = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
    return $dias[(int)$d->format('w')];
}
?>
