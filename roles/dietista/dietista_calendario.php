<?php
require_once __DIR__ . '/../../includes/conexion.php';
requiere_rol('dietista', '../../');

$uid = usuario_id();
$idC = isset($_GET['id_cliente']) ? (int)$_GET['id_cliente'] : 0;

$cliente = null;
if ($idC > 0) {
    $stmt = $conn->prepare("SELECT id, nombre_completo FROM usuarios WHERE id=? AND id_dietista=? AND rol='cliente' AND activo=1");
    $stmt->bind_param('ii', $idC, $uid);
    $stmt->execute();
    $cliente = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$cliente) { header('Location: dietista_calendario.php'); exit; }
}

// MODO LISTADO
if (!$cliente) {
    $stmt = $conn->prepare(
        "SELECT id, nombre_completo FROM usuarios
         WHERE rol='cliente' AND id_dietista=? AND activo=1 ORDER BY nombre_completo"
    );
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $lista = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $base   = '../../';
    $active = 'calendario';
    $titulo = 'Calendario';
    include __DIR__ . '/../../includes/sidebar.php';
    ?>
    <main class="page">
      <section class="card">
        <h2 class="card-title">📆 Selecciona un cliente</h2>
        <?php if (!$lista): ?>
          <p class="text-muted">Sin clientes asignados.</p>
        <?php else: ?>
          <ul class="dt-clients" role="list">
            <?php foreach ($lista as $c): ?>
              <li class="dt-client">
                <a class="dt-client-link" href="?id_cliente=<?= (int)$c['id'] ?>">
                  <div class="chats-avatar"><?= e(mb_strtoupper(mb_substr($c['nombre_completo'], 0, 1, 'UTF-8'), 'UTF-8')) ?></div>
                  <div class="dt-client-info">
                    <div class="dt-client-name"><?= e($c['nombre_completo']) ?></div>
                  </div>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>
    </main>
    <?php include __DIR__ . '/../../includes/footer.php'; ?>
    <?php exit;
}

// MODO CLIENTE (shell)
$base   = '../../';
$active = 'calendario';
$titulo = $cliente['nombre_completo'];
include __DIR__ . '/../../includes/sidebar.php';
?>
<main class="page page-calendario">

  <header class="ck-header">
    <p class="text-soft">Calendario de</p>
    <h2 class="h1"><?= e($cliente['nombre_completo']) ?></h2>
    <div class="form-actions">
      <a class="btn btn-outline btn-mini" href="dietista_calendario.php">← Otros clientes</a>
      <a class="btn btn-outline btn-mini" href="dietista_ficha.php?id=<?= (int)$idC ?>">Ficha</a>
    </div>
  </header>

  <!-- Predicción kg/semana y kg/mes (calculado en JS desde dietas asignadas) -->
  <section class="card cal-pred" id="calPred" hidden>
    <h3 class="card-title">📊 Predicción según dietas asignadas</h3>
    <div class="cal-pred-grid">
      <div class="cal-pred-item">
        <span class="cal-pred-num" id="calPredSem">—</span>
        <span class="cal-pred-lbl">kg esta semana</span>
        <span class="cal-pred-meta" id="calPredSemMeta">—</span>
      </div>
      <div class="cal-pred-item">
        <span class="cal-pred-num" id="calPredMes">—</span>
        <span class="cal-pred-lbl">kg este mes</span>
        <span class="cal-pred-meta" id="calPredMesMeta">—</span>
      </div>
    </div>
    <p class="cal-pred-hint text-muted" id="calPredHint">
      Cálculo: (kcal dieta − TDEE) × días asignados ÷ 7.700 kcal/kg
    </p>
  </section>

  <!-- Paleta de dietas -->
  <section class="card cal-palette">
    <header class="cal-palette-head">
      <h3 class="card-title">🎨 Pinta con una dieta</h3>
    </header>
    <p class="text-soft cal-palette-help" id="calPaletteHelp">
      Selecciona una dieta y arrastra sobre los días. Pulsa la misma para deseleccionar y entrar en <strong>modo borrar</strong>.
    </p>
    <div class="cal-palette-grid cal-palette-grid--compact" id="calPaletteGrid">
      <p class="text-muted cal-palette-loading">Cargando dietas…</p>
    </div>
  </section>

  <!-- Calendario -->
  <section class="card cal-card">
    <nav class="cal-nav" aria-label="Mes">
      <button type="button" class="day-nav-btn" id="calPrev" aria-label="Mes anterior">←</button>
      <button type="button" class="day-nav-current cal-month-btn" id="calMonthLabel" aria-label="Volver a hoy" title="Click: volver a hoy">—</button>
      <button type="button" class="day-nav-btn" id="calNext" aria-label="Mes siguiente">→</button>
    </nav>

    <div class="cal-grid is-editable" id="calGrid" role="grid">
      <div class="cal-dow">L</div><div class="cal-dow">M</div><div class="cal-dow">X</div>
      <div class="cal-dow">J</div><div class="cal-dow">V</div><div class="cal-dow">S</div><div class="cal-dow">D</div>
    </div>

    <p class="text-muted cal-foot-hint">
      💡 Toca un día para detalles · Arrastra para asignar/borrar varios.
    </p>
  </section>

  <!-- Limpiar todo -->
  <section class="card cal-clear-card">
    <button type="button" class="btn btn-danger btn-block" id="btnClearAll">
      🗑️ Limpiar TODO el calendario del cliente
    </button>
  </section>

</main>

<!-- Modal detalle día -->
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
  mode:        'dietista',
  csrf:        <?= json_encode(csrf_token()) ?>,
  id_cliente:  <?= (int)$idC ?>,
  mes_inicial: <?= json_encode(date('Y-m')) ?>,
  base_url:    <?= json_encode($base) ?>
};
</script>
<script src="<?= e($base) ?>js/calendario.js" defer></script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
