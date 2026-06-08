<?php
require_once __DIR__ . '/../../includes/conexion.php';
requiere_rol('admin', '../../');

$uid = usuario_id();

// ============================================================
// MÉTRICAS GLOBALES
// ============================================================
$st = [
    'totalUsr'   => (int)$conn->query("SELECT COUNT(*) c FROM usuarios")->fetch_assoc()['c'],
    'admins'     => (int)$conn->query("SELECT COUNT(*) c FROM usuarios WHERE rol='admin'    AND activo=1")->fetch_assoc()['c'],
    'dietistas'  => (int)$conn->query("SELECT COUNT(*) c FROM usuarios WHERE rol='dietista' AND activo=1")->fetch_assoc()['c'],
    'clientes'   => (int)$conn->query("SELECT COUNT(*) c FROM usuarios WHERE rol='cliente'  AND activo=1")->fetch_assoc()['c'],
    'inactivos'  => (int)$conn->query("SELECT COUNT(*) c FROM usuarios WHERE activo=0")->fetch_assoc()['c'],
    'pendientes' => (int)$conn->query("SELECT COUNT(*) c FROM usuarios WHERE rol='cliente' AND id_dietista IS NULL AND activo=1")->fetch_assoc()['c'],
    'nuevos30'   => (int)$conn->query("SELECT COUNT(*) c FROM usuarios WHERE fecha_registro >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetch_assoc()['c'],
    'activos7d'  => (int)$conn->query("SELECT COUNT(*) c FROM usuarios WHERE ultima_actividad >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND activo=1")->fetch_assoc()['c'],
    'alimentos'  => (int)$conn->query("SELECT COUNT(*) c FROM alimentos")->fetch_assoc()['c'],
    'dietas'     => (int)$conn->query("SELECT COUNT(*) c FROM dietas_base")->fetch_assoc()['c'],
    'plantillas' => (int)$conn->query("SELECT COUNT(*) c FROM dietas_base WHERE id_cliente IS NULL")->fetch_assoc()['c'],
    'consultas'  => (int)$conn->query("SELECT COUNT(*) c FROM consultas")->fetch_assoc()['c'],
    'consu7d'    => (int)$conn->query("SELECT COUNT(*) c FROM consultas WHERE fecha BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)")->fetch_assoc()['c'],
    'checkins7d' => (int)$conn->query("SELECT COUNT(*) c FROM checkins_semanales WHERE fecha_registro >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch_assoc()['c'],
    'banners'    => (int)$conn->query("SELECT COUNT(*) c FROM banners_sistema WHERE activo=1")->fetch_assoc()['c'],
    'msgs7d'     => (int)$conn->query("SELECT COUNT(*) c FROM chats_mensajes WHERE fecha_hora >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch_assoc()['c'],
    'asignaciones'=> (int)$conn->query("SELECT COUNT(*) c FROM calendario_asignaciones")->fetch_assoc()['c'],
    'progresos'  => (int)$conn->query("SELECT COUNT(*) c FROM progresos_metricas")->fetch_assoc()['c'],
];

// Tamaño BD
$dbSize = 0;
$r = $conn->query("SELECT SUM(data_length + index_length) AS s FROM information_schema.TABLES WHERE table_schema=DATABASE()");
if ($r && ($x = $r->fetch_assoc())) $dbSize = (int)$x['s'];
function fmtBytes(int $b): string {
    if ($b < 1024) return $b . ' B';
    if ($b < 1024*1024) return round($b / 1024, 1) . ' KB';
    if ($b < 1024*1024*1024) return round($b / (1024*1024), 1) . ' MB';
    return round($b / (1024*1024*1024), 2) . ' GB';
}

// Top dietistas
$topDietistas = $conn->query(
    "SELECT d.id, d.nombre_completo,
            COUNT(c.id) AS n_clientes,
            SUM(CASE WHEN c.ultima_actividad >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS activos
     FROM usuarios d
     LEFT JOIN usuarios c ON c.id_dietista = d.id AND c.rol='cliente' AND c.activo=1
     WHERE d.rol='dietista' AND d.activo=1
     GROUP BY d.id ORDER BY n_clientes DESC LIMIT 5"
)->fetch_all(MYSQLI_ASSOC);

// Clientes sin dietista
$sinDietista = $conn->query(
    "SELECT id, nombre_completo, email, fecha_registro FROM usuarios
     WHERE rol='cliente' AND id_dietista IS NULL AND activo=1
     ORDER BY fecha_registro DESC LIMIT 5"
)->fetch_all(MYSQLI_ASSOC);

// Últimos usuarios
$nuevosUsr = $conn->query(
    "SELECT id, rol, nombre_completo, email, fecha_registro, activo FROM usuarios
     ORDER BY fecha_registro DESC LIMIT 6"
)->fetch_all(MYSQLI_ASSOC);

// Actividad reciente
$logsRec = $conn->query(
    "SELECT l.accion, l.descripcion, l.fecha_hora, u.nombre_completo
     FROM logs_admin l LEFT JOIN usuarios u ON u.id = l.id_usuario_accion
     ORDER BY l.fecha_hora DESC LIMIT 8"
)->fetch_all(MYSQLI_ASSOC);

// Datos chart: registros peso 14 días
$res = $conn->query(
    "SELECT DATE(fecha_hora) AS dia, COUNT(*) AS n FROM progresos_metricas
     WHERE fecha_hora >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
     GROUP BY DATE(fecha_hora) ORDER BY dia ASC"
);
$mapDia = [];
while ($r = $res->fetch_assoc()) $mapDia[$r['dia']] = (int)$r['n'];
$regDias = [];
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $regDias[] = ['dia' => $d, 'n' => $mapDia[$d] ?? 0];
}

// Top alimentos más usados
$topAlim = $conn->query(
    "SELECT a.id, a.nombre, COUNT(*) AS n
     FROM dieta_alimentos da JOIN alimentos a ON a.id = da.id_alimento
     GROUP BY a.id ORDER BY n DESC LIMIT 8"
)->fetch_all(MYSQLI_ASSOC);

// Tamaño tablas (top 5)
$topTablas = $conn->query(
    "SELECT table_name AS tabla, table_rows AS filas, (data_length + index_length) AS bytes
     FROM information_schema.TABLES WHERE table_schema=DATABASE()
     ORDER BY bytes DESC LIMIT 6"
)->fetch_all(MYSQLI_ASSOC);

$base   = '../../';
$active = 'dashboard';
$titulo = 'Inicio · Admin';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main class="page admin-dash">

  <header class="admin-dash-hero">
    <p class="text-soft">Bienvenido, administrador</p>
    <h1 class="h1">📊 Panel de control</h1>
  </header>

  <!-- ACCIONES PRINCIPALES -->
  <section class="card admin-actions">
    <h3 class="card-title">⚡ Acciones rápidas</h3>
    <div class="admin-actions-grid">
      <a class="dash-quick-btn" href="admin_backup.php"><span>💾</span><span>Backup BD</span></a>
      <a class="dash-quick-btn" href="admin_usuarios.php"><span>👥</span><span>Usuarios</span></a>
      <a class="dash-quick-btn" href="admin_alimentos.php"><span>🥦</span><span>Alimentos</span></a>
      <a class="dash-quick-btn" href="admin_asignaciones.php"><span>🔗</span><span>Asignaciones</span></a>
      <a class="dash-quick-btn" href="admin_banners.php"><span>🖼️</span><span>Banners</span></a>
      <a class="dash-quick-btn" href="admin_logs.php"><span>📜</span><span>Logs</span></a>
    </div>
  </section>

  <!-- KPIs principales -->
  <section class="admin-kpi-grid">
    <div class="admin-kpi admin-kpi--primary">
      <span class="admin-kpi-icon">👥</span>
      <div class="admin-kpi-body">
        <span class="admin-kpi-num"><?= $st['totalUsr'] ?></span>
        <span class="admin-kpi-lbl">Usuarios totales</span>
        <span class="admin-kpi-sub">+<?= $st['nuevos30'] ?> en 30 días</span>
      </div>
    </div>
    <div class="admin-kpi">
      <span class="admin-kpi-icon">🥗</span>
      <div class="admin-kpi-body">
        <span class="admin-kpi-num"><?= $st['clientes'] ?></span>
        <span class="admin-kpi-lbl">Clientes activos</span>
        <span class="admin-kpi-sub"><?= $st['activos7d'] ?> activos 7d</span>
      </div>
    </div>
    <div class="admin-kpi">
      <span class="admin-kpi-icon">👨‍⚕️</span>
      <div class="admin-kpi-body">
        <span class="admin-kpi-num"><?= $st['dietistas'] ?></span>
        <span class="admin-kpi-lbl">Dietistas</span>
        <span class="admin-kpi-sub">
          <?php if ($st['dietistas'] > 0): ?>
            <?= number_format($st['clientes'] / $st['dietistas'], 1, ',', '') ?> clientes/dt
          <?php else: ?>—<?php endif; ?>
        </span>
      </div>
    </div>
    <div class="admin-kpi <?= $st['pendientes'] > 0 ? 'admin-kpi--warn' : '' ?>">
      <span class="admin-kpi-icon">⚠️</span>
      <div class="admin-kpi-body">
        <span class="admin-kpi-num"><?= $st['pendientes'] ?></span>
        <span class="admin-kpi-lbl">Sin dietista</span>
        <span class="admin-kpi-sub">requieren asignación</span>
      </div>
    </div>
  </section>

  <!-- KPIs secundarios -->
  <section class="admin-kpi-grid admin-kpi-grid--sec">
    <div class="admin-kpi-mini"><span class="admin-kpi-mini-num"><?= $st['dietas'] ?></span><span class="admin-kpi-mini-lbl">🍽️ Dietas</span></div>
    <div class="admin-kpi-mini"><span class="admin-kpi-mini-num"><?= $st['plantillas'] ?></span><span class="admin-kpi-mini-lbl">📑 Plantillas</span></div>
    <div class="admin-kpi-mini"><span class="admin-kpi-mini-num"><?= $st['alimentos'] ?></span><span class="admin-kpi-mini-lbl">🥦 Alimentos</span></div>
    <div class="admin-kpi-mini"><span class="admin-kpi-mini-num"><?= $st['consultas'] ?></span><span class="admin-kpi-mini-lbl">📅 Consultas</span></div>
    <div class="admin-kpi-mini"><span class="admin-kpi-mini-num"><?= $st['consu7d'] ?></span><span class="admin-kpi-mini-lbl">📅 Próx. 7d</span></div>
    <div class="admin-kpi-mini"><span class="admin-kpi-mini-num"><?= $st['checkins7d'] ?></span><span class="admin-kpi-mini-lbl">📝 Check-ins 7d</span></div>
    <div class="admin-kpi-mini"><span class="admin-kpi-mini-num"><?= $st['msgs7d'] ?></span><span class="admin-kpi-mini-lbl">💬 Msgs 7d</span></div>
    <div class="admin-kpi-mini"><span class="admin-kpi-mini-num"><?= $st['progresos'] ?></span><span class="admin-kpi-mini-lbl">📈 Pesos</span></div>
    <div class="admin-kpi-mini"><span class="admin-kpi-mini-num"><?= $st['asignaciones'] ?></span><span class="admin-kpi-mini-lbl">📆 Asignaciones</span></div>
    <div class="admin-kpi-mini"><span class="admin-kpi-mini-num"><?= $st['inactivos'] ?></span><span class="admin-kpi-mini-lbl">🚫 Inactivos</span></div>
    <div class="admin-kpi-mini"><span class="admin-kpi-mini-num"><?= $st['banners'] ?></span><span class="admin-kpi-mini-lbl">📢 Banners</span></div>
    <div class="admin-kpi-mini"><span class="admin-kpi-mini-num"><?= e(fmtBytes($dbSize)) ?></span><span class="admin-kpi-mini-lbl">💾 Tamaño BD</span></div>
  </section>

  <!-- BACKUP CARD destacada -->
  <section class="card admin-backup-card">
    <div class="admin-backup-info">
      <h3 class="card-title">💾 Copia de seguridad</h3>
      <p class="text-soft">Descarga un archivo <code>.sql</code> con TODA la base de datos. Inclúyelo en tu rutina de backups periódicos.</p>
      <p class="text-muted">Última operación de admin: <?= $logsRec ? e(date('d/m/Y H:i', strtotime($logsRec[0]['fecha_hora']))) : '—' ?></p>
    </div>
    <a href="admin_backup.php" class="btn btn-primary btn-block admin-backup-btn">
      ⬇️ Descargar backup ahora
    </a>
  </section>

  <!-- Mini gráfica registros 14d -->
  <section class="card">
    <h3 class="card-title">📈 Registros de peso · últimos 14 días</h3>
    <div class="admin-bars">
      <?php $maxN = max(array_column($regDias, 'n')) ?: 1; ?>
      <?php foreach ($regDias as $rd):
          $hPct = round(($rd['n'] / $maxN) * 100);
          $diaCorto = date('d/m', strtotime($rd['dia']));
          $esHoy = $rd['dia'] === date('Y-m-d');
      ?>
        <div class="admin-bar<?= $esHoy ? ' admin-bar--today' : '' ?>" title="<?= e($diaCorto) ?>: <?= (int)$rd['n'] ?> registros">
          <span class="admin-bar-num"><?= (int)$rd['n'] ?></span>
          <span class="admin-bar-fill" style="height: <?= $hPct ?>%;"></span>
          <span class="admin-bar-lbl"><?= e($diaCorto) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- Aviso clientes sin dietista -->
  <?php if ($sinDietista): ?>
    <section class="card admin-warn-card">
      <header class="dash-card-header">
        <h3 class="card-title">⚠️ Clientes sin dietista (<?= count($sinDietista) ?>)</h3>
        <a class="dash-card-link" href="admin_asignaciones.php">Asignar →</a>
      </header>
      <ul class="admin-mini-list" role="list">
        <?php foreach ($sinDietista as $c): ?>
          <li>
            <div class="admin-mini-list-info">
              <strong><?= e($c['nombre_completo']) ?></strong>
              <span class="text-muted"><?= e($c['email']) ?></span>
            </div>
            <span class="text-muted admin-mini-list-meta">
              Desde <?= e(date('d/m/Y', strtotime($c['fecha_registro']))) ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>

  <!-- Dos columnas: top dietistas + top alimentos -->
  <div class="grid-2">
    <section class="card">
      <header class="dash-card-header">
        <h3 class="card-title">🏆 Top dietistas</h3>
        <a class="dash-card-link" href="admin_usuarios.php?rol=dietista">Ver todos →</a>
      </header>
      <?php if (!$topDietistas): ?>
        <p class="text-muted">Sin dietistas.</p>
      <?php else: ?>
        <ul class="admin-rank-list" role="list">
          <?php foreach ($topDietistas as $i => $d): ?>
            <li class="admin-rank-item">
              <span class="admin-rank-pos">#<?= $i + 1 ?></span>
              <div class="admin-rank-info">
                <strong><?= e($d['nombre_completo']) ?></strong>
              </div>
              <div class="admin-rank-stats">
                <span class="admin-rank-big"><?= (int)$d['n_clientes'] ?></span>
                <span class="text-muted">cl.</span>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="card">
      <h3 class="card-title">🥗 Alimentos más usados</h3>
      <?php if (!$topAlim): ?>
        <p class="text-muted">Sin datos aún.</p>
      <?php else: ?>
        <ul class="admin-rank-list" role="list">
          <?php foreach ($topAlim as $i => $a): ?>
            <li class="admin-rank-item">
              <span class="admin-rank-pos">#<?= $i + 1 ?></span>
              <div class="admin-rank-info"><strong><?= e($a['nombre']) ?></strong></div>
              <div class="admin-rank-stats">
                <span class="admin-rank-big"><?= (int)$a['n'] ?></span>
                <span class="text-muted">usos</span>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  </div>

  <!-- Últimos usuarios + Actividad reciente -->
  <div class="grid-2">
    <section class="card">
      <header class="dash-card-header">
        <h3 class="card-title">🆕 Últimos registros</h3>
        <a class="dash-card-link" href="admin_usuarios.php">Gestionar →</a>
      </header>
      <ul class="admin-mini-list" role="list">
        <?php foreach ($nuevosUsr as $u): ?>
          <li>
            <div class="admin-mini-list-info">
              <strong><?= e($u['nombre_completo']) ?></strong>
              <span class="text-muted"><?= e($u['email']) ?></span>
            </div>
            <div class="admin-mini-list-meta">
              <span class="dt-pill dt-pill--<?= $u['rol']==='admin'?'warn':($u['rol']==='dietista'?'info':'ok') ?>"><?= e($u['rol']) ?></span>
              <span class="text-muted"><?= e(date('d/m/Y', strtotime($u['fecha_registro']))) ?></span>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>

    <section class="card">
      <header class="dash-card-header">
        <h3 class="card-title">📜 Actividad reciente</h3>
        <a class="dash-card-link" href="admin_logs.php">Ver logs →</a>
      </header>
      <?php if (!$logsRec): ?>
        <p class="text-muted">Sin actividad.</p>
      <?php else: ?>
        <ul class="admin-log-list" role="list">
          <?php foreach ($logsRec as $l): ?>
            <li class="admin-log-item">
              <span class="admin-log-icon">📝</span>
              <div class="admin-log-body">
                <div class="admin-log-head">
                  <strong><?= e($l['accion']) ?></strong>
                  <span class="text-muted"><?= e($l['nombre_completo'] ?? '—') ?></span>
                </div>
                <p class="admin-log-desc"><?= e($l['descripcion']) ?></p>
              </div>
              <span class="text-muted admin-log-time"><?= e(date('d/m H:i', strtotime($l['fecha_hora']))) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  </div>

  <!-- Tablas más grandes (info técnica) -->
  <section class="card">
    <h3 class="card-title">💽 Tablas más grandes</h3>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr><th>Tabla</th><th class="num">Filas</th><th class="num">Tamaño</th></tr>
        </thead>
        <tbody>
          <?php foreach ($topTablas as $t): ?>
            <tr>
              <td><code><?= e($t['tabla']) ?></code></td>
              <td class="num"><?= number_format((int)$t['filas'], 0, ',', '.') ?></td>
              <td class="num"><?= e(fmtBytes((int)$t['bytes'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
