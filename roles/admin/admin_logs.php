<?php
require_once __DIR__ . '/../../includes/conexion.php';
requiere_rol('admin', '../../');

$uid   = usuario_id();
$ok    = '';
$error = '';

// ============================================================
// POST: limpiar logs antiguos
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $error = 'Sesión expirada.';
    } elseif (($_POST['accion'] ?? '') === 'limpiar') {
        $dias = (int)($_POST['dias'] ?? 90);
        if ($dias < 1) $dias = 90;
        $stmt = $conn->prepare("DELETE FROM logs_admin WHERE fecha_hora < DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmt->bind_param('i', $dias);
        $stmt->execute();
        $bor = $stmt->affected_rows;
        $stmt->close();
        log_admin($conn, $uid, 'LIMPIAR_LOGS', "Limpiados {$bor} log(s) anteriores a {$dias} días");
        $ok = "Borrados {$bor} log(s) antiguos.";
    }
}

// ============================================================
// Filtros
// ============================================================
$accionF   = trim($_GET['accion_f'] ?? '');
$userF     = isset($_GET['user']) ? (int)$_GET['user'] : 0;
$fechaDes  = $_GET['desde']  ?? '';
$fechaHas  = $_GET['hasta']  ?? '';
$pagina    = max(1, (int)($_GET['p'] ?? 1));
$porPag    = 50;
$offset    = ($pagina - 1) * $porPag;

$where = "1=1"; $params = []; $tipos = '';

if ($accionF !== '') {
    $where .= " AND l.accion = ?"; $params[] = $accionF; $tipos .= 's';
}
if ($userF > 0) {
    $where .= " AND l.id_usuario_accion = ?"; $params[] = $userF; $tipos .= 'i';
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaDes)) {
    $where .= " AND l.fecha_hora >= ?"; $params[] = $fechaDes . ' 00:00:00'; $tipos .= 's';
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaHas)) {
    $where .= " AND l.fecha_hora <= ?"; $params[] = $fechaHas . ' 23:59:59'; $tipos .= 's';
}

// Total para paginación
$sqlCount = "SELECT COUNT(*) c FROM logs_admin l WHERE $where";
$stmt = $conn->prepare($sqlCount);
if ($tipos !== '') $stmt->bind_param($tipos, ...$params);
$stmt->execute();
$total = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();
$totalPag = max(1, (int)ceil($total / $porPag));

// Datos
$sql = "SELECT l.*, u.nombre_completo, u.rol
        FROM logs_admin l
        LEFT JOIN usuarios u ON u.id = l.id_usuario_accion
        WHERE $where
        ORDER BY l.fecha_hora DESC
        LIMIT $porPag OFFSET $offset";
$stmt = $conn->prepare($sql);
if ($tipos !== '') $stmt->bind_param($tipos, ...$params);
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Tipos de acción únicos (para el filtro)
$acciones = $conn->query("SELECT DISTINCT accion FROM logs_admin ORDER BY accion")->fetch_all(MYSQLI_ASSOC);

// Usuarios que han generado logs
$users = $conn->query(
    "SELECT DISTINCT u.id, u.nombre_completo
     FROM logs_admin l JOIN usuarios u ON u.id = l.id_usuario_accion
     ORDER BY u.nombre_completo"
)->fetch_all(MYSQLI_ASSOC);

$base   = '../../';
$active = 'logs';
$titulo = 'Logs del sistema';
include __DIR__ . '/../../includes/sidebar.php';

function color_accion(string $a): string {
    if (strpos($a, 'BORRAR') !== false)  return 'log-tag--danger';
    if (strpos($a, 'CREAR') !== false)   return 'log-tag--success';
    if (strpos($a, 'EDITAR') !== false || strpos($a, 'TOGGLE') !== false) return 'log-tag--info';
    if (strpos($a, 'LOGIN') !== false)   return 'log-tag--neutral';
    return 'log-tag--neutral';
}
?>
<main class="page">

  <?php if ($ok):    ?><div class="alert alert-success" role="status"><?= e($ok) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"  role="alert"><?= e($error) ?></div><?php endif; ?>

  <!-- Filtros -->
  <form method="get" class="card">
    <h2 class="card-title">🔎 Filtros</h2>

    <div class="grid-2">
      <label class="field">
        <span class="field-label">Acción</span>
        <select class="field-select" name="accion_f">
          <option value="">— Todas —</option>
          <?php foreach ($acciones as $a): ?>
            <option value="<?= e($a['accion']) ?>" <?= $accionF === $a['accion'] ? 'selected' : '' ?>><?= e($a['accion']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field">
        <span class="field-label">Usuario</span>
        <select class="field-select" name="user">
          <option value="0">— Todos —</option>
          <?php foreach ($users as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= $userF === (int)$u['id'] ? 'selected' : '' ?>>
              <?= e($u['nombre_completo']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>

    <div class="grid-2">
      <label class="field">
        <span class="field-label">Desde</span>
        <input class="field-input" type="date" name="desde" value="<?= e($fechaDes) ?>">
      </label>
      <label class="field">
        <span class="field-label">Hasta</span>
        <input class="field-input" type="date" name="hasta" value="<?= e($fechaHas) ?>">
      </label>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary btn-block">Filtrar</button>
      <a class="btn btn-outline btn-block" href="admin_logs.php">Limpiar filtros</a>
    </div>
  </form>

  <!-- Listado -->
  <section class="card">
    <h3 class="card-title">📜 <?= number_format($total, 0, ',', '.') ?> log<?= $total === 1 ? '' : 's' ?>
      <span class="text-muted">· Página <?= $pagina ?>/<?= $totalPag ?></span>
    </h3>

    <?php if (!$logs): ?>
      <p class="text-muted">Sin resultados.</p>
    <?php else: ?>
      <ul class="log-list" role="list">
        <?php foreach ($logs as $l): ?>
          <li class="log-row">
            <div class="log-meta">
              <span class="log-tag <?= color_accion($l['accion']) ?>"><?= e($l['accion']) ?></span>
              <span class="log-time"><?= e(date('d/m/Y H:i', strtotime($l['fecha_hora']))) ?></span>
            </div>
            <div class="log-desc"><?= e($l['descripcion']) ?></div>
            <div class="log-user text-muted">
              👤 <?= e($l['nombre_completo'] ?? 'Usuario eliminado') ?>
              <?php if (!empty($l['rol'])): ?>· <?= e($l['rol']) ?><?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>

      <!-- Paginación -->
      <?php if ($totalPag > 1): ?>
        <nav class="pager" aria-label="Paginación">
          <?php
          $qs = $_GET; unset($qs['p']);
          $base_url = '?' . http_build_query($qs);
          ?>
          <?php if ($pagina > 1): ?>
            <a class="btn btn-outline btn-mini" href="<?= e($base_url) ?>&p=<?= $pagina - 1 ?>">←</a>
          <?php else: ?><span class="btn btn-outline btn-mini is-disabled">←</span><?php endif; ?>

          <span class="pager-info"><?= $pagina ?> / <?= $totalPag ?></span>

          <?php if ($pagina < $totalPag): ?>
            <a class="btn btn-outline btn-mini" href="<?= e($base_url) ?>&p=<?= $pagina + 1 ?>">→</a>
          <?php else: ?><span class="btn btn-outline btn-mini is-disabled">→</span><?php endif; ?>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <!-- Limpieza -->
  <form method="post" class="card" onsubmit="return confirm('¿Borrar logs anteriores a los días indicados?');">
    <h3 class="card-title">🧹 Limpiar logs antiguos</h3>
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="accion" value="limpiar">

    <label class="field">
      <span class="field-label">Borrar logs con más de N días</span>
      <input class="field-input" type="number" name="dias" min="1" max="3650" value="90">
    </label>

    <button type="submit" class="btn btn-danger btn-block">Limpiar logs</button>
  </form>

</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>