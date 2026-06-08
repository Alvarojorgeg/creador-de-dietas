<?php
require_once __DIR__ . '/../../includes/conexion.php';
requiere_rol('dietista', '../../');

$uid   = usuario_id();
$ok    = '';
$error = '';

// ============================================================
// POST: borrar
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $error = 'Sesión expirada.';
    } elseif (($_POST['accion'] ?? '') === 'borrar') {
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("DELETE FROM consultas WHERE id=? AND id_dietista=?");
        $stmt->bind_param('ii', $id, $uid);
        $ok = $stmt->execute() && $stmt->affected_rows > 0 ? 'Consulta borrada.' : 'No se pudo borrar.';
        $stmt->close();
    }
}

// ============================================================
// Filtros
// ============================================================
$filtro   = $_GET['filtro']     ?? 'todas';    // todas | proximas | pasadas
$idClienteF = isset($_GET['id_cliente']) ? (int)$_GET['id_cliente'] : 0;

$where = "c.id_dietista = ?";
$params = [$uid]; $tipos = 'i';

if ($filtro === 'proximas')      { $where .= " AND c.fecha >= NOW()"; }
elseif ($filtro === 'pasadas')   { $where .= " AND c.fecha <  NOW()"; }

if ($idClienteF > 0) {
    $where .= " AND c.id_cliente = ?";
    $params[] = $idClienteF; $tipos .= 'i';
}

$order = $filtro === 'pasadas' ? "c.fecha DESC" : "c.fecha ASC";

$sql = "SELECT c.id, c.fecha, c.duracion_min, c.tipo, c.asistio, c.proxima_cita,
               u.id AS id_cliente, u.nombre_completo
        FROM consultas c
        JOIN usuarios u ON u.id = c.id_cliente
        WHERE $where
        ORDER BY $order";
$stmt = $conn->prepare($sql);
$stmt->bind_param($tipos, ...$params);
$stmt->execute();
$consultas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Lista de clientes para filtro
$stmt = $conn->prepare("SELECT id, nombre_completo FROM usuarios WHERE rol='cliente' AND id_dietista=? AND activo=1 ORDER BY nombre_completo");
$stmt->bind_param('i', $uid);
$stmt->execute();
$clientes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$base   = '../../';
$active = 'consultas';
$titulo = 'Consultas';
include __DIR__ . '/../../includes/sidebar.php';

function mes_corto_es2(int $ts): string {
    $m = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
    return $m[(int)date('n', $ts) - 1];
}
?>
<main class="page">

  <?php if ($ok):    ?><div class="alert alert-success" role="status"><?= e($ok) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"  role="alert"><?= e($error) ?></div><?php endif; ?>

  <!-- Acciones -->
  <div class="form-actions">
    <a class="btn btn-primary btn-block" href="dietista_consulta_form.php">➕ Nueva consulta</a>
  </div>

  <!-- Filtros -->
  <form method="get" class="card">
    <div class="grid-2">
      <label class="field">
        <span class="field-label">Estado</span>
        <select class="field-select" name="filtro" onchange="this.form.submit()">
          <option value="todas"    <?= $filtro==='todas'    ? 'selected' : '' ?>>Todas</option>
          <option value="proximas" <?= $filtro==='proximas' ? 'selected' : '' ?>>Próximas</option>
          <option value="pasadas"  <?= $filtro==='pasadas'  ? 'selected' : '' ?>>Pasadas</option>
        </select>
      </label>
      <label class="field">
        <span class="field-label">Cliente</span>
        <select class="field-select" name="id_cliente" onchange="this.form.submit()">
          <option value="0">— Todos —</option>
          <?php foreach ($clientes as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $idClienteF === (int)$c['id'] ? 'selected' : '' ?>>
              <?= e($c['nombre_completo']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
  </form>

  <!-- Lista -->
  <section class="card">
    <h3 class="card-title">📅 <?= count($consultas) ?> consulta<?= count($consultas) === 1 ? '' : 's' ?></h3>

    <?php if (!$consultas): ?>
      <p class="text-muted">Sin consultas con esos filtros.</p>
    <?php else: ?>
      <ul class="cons-list" role="list">
        <?php foreach ($consultas as $c):
          $ts = strtotime($c['fecha']);
          $esProxima = $ts >= time();
        ?>
          <li class="cons-item<?= $esProxima ? ' cons-item--proxima' : '' ?>">
            <div class="cons-date">
              <div class="cons-date-day"><?= e(date('d', $ts)) ?></div>
              <div class="cons-date-mon"><?= e(strtoupper(mes_corto_es2($ts))) ?></div>
            </div>
            <div class="cons-body">
              <div class="cons-time"><?= e(date('H:i', $ts)) ?> · <?= (int)$c['duracion_min'] ?> min</div>
              <div class="cons-tipo">
                <a href="dietista_ficha.php?id=<?= (int)$c['id_cliente'] ?>"><?= e($c['nombre_completo']) ?></a>
                · <?= e(ucfirst($c['tipo'])) ?>
              </div>
              <?php if (!$esProxima && (int)$c['asistio'] === 0): ?>
                <span class="cons-flag">No asistió</span>
              <?php endif; ?>

              <div class="form-actions">
                <a class="btn btn-outline btn-mini" href="dietista_consulta_form.php?id=<?= (int)$c['id'] ?>">✏️ Editar</a>
                <form method="post" class="inline-form" onsubmit="return confirm('¿Borrar esta consulta?');">
                  <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="accion" value="borrar">
                  <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                  <button type="submit" class="btn btn-ghost btn-mini">🗑️</button>
                </form>
              </div>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>