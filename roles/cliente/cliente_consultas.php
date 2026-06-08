<?php
require_once __DIR__ . '/../../includes/conexion.php';
requiere_rol('cliente', '../../');

$uid = usuario_id();

// --- Cargar consultas (todas, separadas en próximas y pasadas) ---
$stmt = $conn->prepare(
    "SELECT c.id, c.fecha, c.duracion_min, c.tipo, c.asistio,
            c.notas_compartidas, c.plan_siguiente, c.proxima_cita,
            u.nombre_completo AS dietista
     FROM consultas c
     JOIN usuarios u ON u.id = c.id_dietista
     WHERE c.id_cliente = ?
     ORDER BY c.fecha DESC"
);
$stmt->bind_param('i', $uid);
$stmt->execute();
$todas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$ahora    = new DateTime('now');
$proximas = [];
$pasadas  = [];
foreach ($todas as $c) {
    $fechaC = new DateTime($c['fecha']);
    if ($fechaC >= $ahora) $proximas[] = $c;
    else                   $pasadas[]  = $c;
}
// Próximas en orden ascendente
usort($proximas, function ($a, $b) { return strcmp($a['fecha'], $b['fecha']); });

$base   = '../../';
$active = 'consultas';
$titulo = 'Consultas';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main class="page">

  <!-- PRÓXIMAS -->
  <section class="card">
    <h2 class="card-title">📅 Próximas consultas</h2>
    <?php if (!$proximas): ?>
      <p class="text-muted">No tienes consultas programadas.</p>
    <?php else: ?>
      <ul class="cons-list" role="list">
        <?php foreach ($proximas as $c): ?>
          <li class="cons-item cons-item--proxima">
            <div class="cons-date">
              <div class="cons-date-day"><?= e(date('d', strtotime($c['fecha']))) ?></div>
              <div class="cons-date-mon"><?= e(strtoupper(mes_corto_es(strtotime($c['fecha'])))) ?></div>
            </div>
            <div class="cons-body">
              <div class="cons-time"><?= e(date('H:i', strtotime($c['fecha']))) ?> · <?= (int)$c['duracion_min'] ?> min</div>
              <div class="cons-tipo"><?= e(ucfirst($c['tipo'])) ?> con <?= e($c['dietista']) ?></div>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <!-- PASADAS -->
  <section class="card">
    <h2 class="card-title">🗂️ Consultas anteriores</h2>
    <?php if (!$pasadas): ?>
      <p class="text-muted">Aún no has tenido consultas.</p>
    <?php else: ?>
      <ul class="cons-list" role="list">
        <?php foreach ($pasadas as $c): ?>
          <li class="cons-item">
            <div class="cons-date">
              <div class="cons-date-day"><?= e(date('d', strtotime($c['fecha']))) ?></div>
              <div class="cons-date-mon"><?= e(strtoupper(mes_corto_es(strtotime($c['fecha'])))) ?></div>
            </div>
            <div class="cons-body">
              <div class="cons-time"><?= e(date('H:i', strtotime($c['fecha']))) ?> · <?= (int)$c['duracion_min'] ?> min</div>
              <div class="cons-tipo"><?= e(ucfirst($c['tipo'])) ?> con <?= e($c['dietista']) ?></div>

              <?php if ((int)$c['asistio'] === 0): ?>
                <span class="cons-flag">No asistió</span>
              <?php endif; ?>

              <?php if (!empty($c['notas_compartidas'])): ?>
                <details class="cons-details">
                  <summary>📝 Notas</summary>
                  <p><?= nl2br(e($c['notas_compartidas'])) ?></p>
                </details>
              <?php endif; ?>

              <?php if (!empty($c['plan_siguiente'])): ?>
                <details class="cons-details">
                  <summary>📌 Plan para la próxima</summary>
                  <p><?= nl2br(e($c['plan_siguiente'])) ?></p>
                </details>
              <?php endif; ?>

              <?php if (!empty($c['proxima_cita'])): ?>
                <div class="cons-next">Próxima cita sugerida: <strong><?= e(date('d/m/Y', strtotime($c['proxima_cita']))) ?></strong></div>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

</main>

<?php
function mes_corto_es(int $ts): string {
    $meses = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
    return $meses[(int)date('n', $ts) - 1];
}
include __DIR__ . '/../../includes/footer.php';
?>