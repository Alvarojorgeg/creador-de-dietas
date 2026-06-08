<?php
require_once __DIR__ . '/../../includes/conexion.php';
requiere_rol('dietista', '../../');

$uid = usuario_id();

// ----------- Lunes de esta semana -----------
$hoy   = new DateTime('today');
$dow   = (int)$hoy->format('N');
$lunes = (clone $hoy)->modify('-' . ($dow - 1) . ' days')->format('Y-m-d');

// ----------- Estadísticas -----------
$stmt = $conn->prepare(
    "SELECT COUNT(*) c FROM usuarios WHERE rol='cliente' AND id_dietista=? AND activo=1"
);
$stmt->bind_param('i', $uid);
$stmt->execute();
$totalClientes = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

// Consultas próximas 7 días
$stmt = $conn->prepare(
    "SELECT COUNT(*) c FROM consultas
     WHERE id_dietista=? AND fecha BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)"
);
$stmt->bind_param('i', $uid);
$stmt->execute();
$consultas7 = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

// Mensajes sin leer
$stmt = $conn->prepare(
    "SELECT COUNT(*) c FROM chats_mensajes WHERE id_destinatario=? AND leido=0"
);
$stmt->bind_param('i', $uid);
$stmt->execute();
$mensajesSinLeer = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

// Clientes que NO han hecho check-in esta semana
$stmt = $conn->prepare(
    "SELECT COUNT(*) c FROM usuarios u
     WHERE u.rol='cliente' AND u.id_dietista=? AND u.activo=1
       AND NOT EXISTS (
         SELECT 1 FROM checkins_semanales ck
         WHERE ck.id_cliente=u.id AND ck.semana_inicio=?
       )"
);
$stmt->bind_param('is', $uid, $lunes);
$stmt->execute();
$checkinPendientes = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

// ----------- Próximas consultas (5) -----------
$stmt = $conn->prepare(
    "SELECT c.id, c.fecha, c.tipo, c.duracion_min, u.nombre_completo, u.id AS id_cliente
     FROM consultas c
     JOIN usuarios u ON u.id = c.id_cliente
     WHERE c.id_dietista=? AND c.fecha >= NOW()
     ORDER BY c.fecha ASC LIMIT 5"
);
$stmt->bind_param('i', $uid);
$stmt->execute();
$proxConsultas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ----------- Listado de clientes con resumen -----------
$stmt = $conn->prepare(
    "SELECT u.id, u.nombre_completo, u.email, u.ultima_actividad,
            (SELECT peso_kg FROM progresos_metricas p WHERE p.id_cliente=u.id ORDER BY p.fecha_hora DESC LIMIT 1) AS peso_actual,
            (SELECT id FROM checkins_semanales ck WHERE ck.id_cliente=u.id AND ck.semana_inicio=? LIMIT 1) AS ckin_id
     FROM usuarios u
     WHERE u.rol='cliente' AND u.id_dietista=? AND u.activo=1
     ORDER BY u.nombre_completo ASC"
);
$stmt->bind_param('si', $lunes, $uid);
$stmt->execute();
$clientes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$base   = '../../';
$active = 'dashboard';
$titulo = 'Inicio';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main class="page">

  <section class="dash-hero">
    <p class="dash-greeting">Hola, <?= e(strtok($_SESSION['usuario_nombre'], ' ')) ?> 👋</p>
    <h2 class="dash-day"><?= e(dia_es_largo($hoy)) ?></h2>
  </section>

  <!-- Métricas rápidas -->
  <section class="dt-stats">
    <a class="dt-stat" href="dietista_ficha.php">
      <span class="dt-stat-num"><?= $totalClientes ?></span>
      <span class="dt-stat-lbl">Clientes</span>
    </a>
    <a class="dt-stat" href="dietista_calendario.php">
      <span class="dt-stat-num"><?= $consultas7 ?></span>
      <span class="dt-stat-lbl">Consultas 7 días</span>
    </a>
    <a class="dt-stat<?= $mensajesSinLeer > 0 ? ' dt-stat--alert' : '' ?> "href="../../mensajes.php">
      <span class="dt-stat-num"><?= $mensajesSinLeer ?></span>
      <span class="dt-stat-lbl">Mensajes sin leer</span>
    </a>
    <a class="dt-stat<?= $checkinPendientes > 0 ? ' dt-stat--warn' : '' ?>" href="dietista_checkin.php">
      <span class="dt-stat-num"><?= $checkinPendientes ?></span>
      <span class="dt-stat-lbl">Check-in pendientes</span>
    </a>
  </section>

  <!-- Próximas consultas -->
  <article class="card">
    <header class="dash-card-header">
      <h3 class="card-title">📅 Próximas consultas</h3>
      <a class="dash-card-link" href="dietista_calendario.php">Ver todas</a>
    </header>

    <?php if (!$proxConsultas): ?>
      <p class="text-muted">No tienes consultas programadas.</p>
    <?php else: ?>
      <ul class="cons-list" role="list">
        <?php foreach ($proxConsultas as $c): ?>
          <li class="cons-item cons-item--proxima">
            <div class="cons-date">
              <div class="cons-date-day"><?= e(date('d', strtotime($c['fecha']))) ?></div>
              <div class="cons-date-mon"><?= e(strtoupper(mes_corto_es(strtotime($c['fecha'])))) ?></div>
            </div>
            <div class="cons-body">
              <div class="cons-time"><?= e(date('H:i', strtotime($c['fecha']))) ?> · <?= (int)$c['duracion_min'] ?> min</div>
              <div class="cons-tipo">
                <a href="dietista_ficha.php?id=<?= (int)$c['id_cliente'] ?>"><?= e($c['nombre_completo']) ?></a>
                · <?= e(ucfirst($c['tipo'])) ?>
              </div>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </article>

  <!-- Mis clientes -->
  <article class="card">
    <header class="dash-card-header">
      <h3 class="card-title">👥 Mis clientes</h3>
      <a class="dash-card-link" href="dietista_ficha.php">Ver todos</a>
    </header>

    <?php if (!$clientes): ?>
      <p class="text-muted">Aún no tienes clientes asignados.</p>
    <?php else: ?>
      <ul class="dt-clients" role="list">
        <?php foreach ($clientes as $c): ?>
          <li class="dt-client">
            <a class="dt-client-link" href="dietista_ficha.php?id=<?= (int)$c['id'] ?>">
              <div class="chats-avatar">
                <?= e(mb_strtoupper(mb_substr($c['nombre_completo'], 0, 1, 'UTF-8'), 'UTF-8')) ?>
              </div>
              <div class="dt-client-info">
                <div class="dt-client-name"><?= e($c['nombre_completo']) ?></div>
                <div class="dt-client-meta">
                  <?php if ($c['peso_actual'] !== null): ?>
                    <span><?= e(rtrim(rtrim((string)$c['peso_actual'], '0'), '.')) ?> kg</span>
                  <?php else: ?>
                    <span class="text-muted">Sin peso</span>
                  <?php endif; ?>
                  <?php if ($c['ckin_id']): ?>
                    <span class="dt-pill dt-pill--ok">Check-in ✓</span>
                  <?php else: ?>
                    <span class="dt-pill dt-pill--warn">Sin check-in</span>
                  <?php endif; ?>
                </div>
              </div>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </article>

</main>

<?php
function dia_es_largo(DateTime $d): string {
    $dias  = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
    $meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    return $dias[(int)$d->format('w')] . ', ' . (int)$d->format('j') . ' de ' . $meses[(int)$d->format('n') - 1];
}
function mes_corto_es(int $ts): string {
    $meses = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
    return $meses[(int)date('n', $ts) - 1];
}
include __DIR__ . '/../../includes/footer.php';
?>