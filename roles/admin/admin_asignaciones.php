<?php
require_once __DIR__ . '/../../includes/conexion.php';
requiere_rol('admin', '../../');

$uid   = usuario_id();
$ok    = '';
$error = '';

// ============================================================
// POST: cambiar dietista / bulk asignar
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $error = 'Sesión expirada.';
    } else {
        $accion = $_POST['accion'] ?? '';

        if ($accion === 'asignar') {
            $idCliente  = (int)$_POST['id_cliente'];
            $idDietista = (int)($_POST['id_dietista'] ?? 0);
            $valDieta   = $idDietista > 0 ? $idDietista : null;

            // Verificar que el dietista es válido (o vacío)
            if ($valDieta) {
                $stmt = $conn->prepare("SELECT id FROM usuarios WHERE id=? AND rol='dietista' AND activo=1");
                $stmt->bind_param('i', $valDieta);
                $stmt->execute();
                if (!$stmt->get_result()->fetch_assoc()) {
                    $error = 'Dietista no válido.';
                }
                $stmt->close();
            }

            if (!$error) {
                $stmt = $conn->prepare("UPDATE usuarios SET id_dietista=? WHERE id=? AND rol='cliente'");
                $stmt->bind_param('ii', $valDieta, $idCliente);
                if ($stmt->execute()) {
                    log_admin($conn, $uid, 'ASIGNAR_DIETISTA', "Cliente #{$idCliente} → dietista #" . ($valDieta ?? '(ninguno)'));
                    $ok = 'Asignación actualizada.';
                } else $error = 'No se pudo actualizar.';
                $stmt->close();
            }
        }
        elseif ($accion === 'asignar_lote') {
            $idDietista = (int)($_POST['id_dietista'] ?? 0);
            $ids = $_POST['clientes'] ?? [];
            $ids = array_filter(array_map('intval', is_array($ids) ? $ids : []));

            if (empty($ids)) {
                $error = 'No has seleccionado ningún cliente.';
            } else {
                $valD = $idDietista > 0 ? $idDietista : null;
                if ($valD) {
                    $stmt = $conn->prepare("SELECT id FROM usuarios WHERE id=? AND rol='dietista' AND activo=1");
                    $stmt->bind_param('i', $valD);
                    $stmt->execute();
                    if (!$stmt->get_result()->fetch_assoc()) {
                        $error = 'Dietista no válido.';
                    }
                    $stmt->close();
                }
                if (!$error) {
                    $afectados = 0;
                    foreach ($ids as $idC) {
                        $stmt = $conn->prepare("UPDATE usuarios SET id_dietista=? WHERE id=? AND rol='cliente'");
                        $stmt->bind_param('ii', $valD, $idC);
                        $stmt->execute();
                        if ($stmt->affected_rows > 0) $afectados++;
                        $stmt->close();
                    }
                    log_admin($conn, $uid, 'ASIGNAR_LOTE', "Asignados {$afectados} cliente(s) al dietista #" . ($valD ?? '(ninguno)'));
                    $ok = "Se han asignado {$afectados} cliente(s).";
                }
            }
        }
    }
}

// --- Filtros ---
$filtro     = $_GET['filtro']     ?? 'todos'; // todos | sin_dietista | por_dietista
$dietistaF  = isset($_GET['dietista']) ? (int)$_GET['dietista'] : 0;
$q          = trim($_GET['q'] ?? '');

$where = "u.rol='cliente' AND u.activo=1"; $params = []; $tipos = '';
if ($filtro === 'sin_dietista')        $where .= " AND u.id_dietista IS NULL";
elseif ($filtro === 'por_dietista' && $dietistaF > 0) {
    $where .= " AND u.id_dietista = ?";
    $params[] = $dietistaF; $tipos .= 'i';
}
if ($q !== '') {
    $where .= " AND (u.nombre_completo LIKE ? OR u.email LIKE ?)";
    $like = '%' . $q . '%'; $params[] = $like; $params[] = $like; $tipos .= 'ss';
}

$sql = "SELECT u.id, u.nombre_completo, u.email, u.id_dietista,
               d.nombre_completo AS dietista_nombre
        FROM usuarios u
        LEFT JOIN usuarios d ON d.id = u.id_dietista
        WHERE $where
        ORDER BY u.nombre_completo
        LIMIT 200";
$stmt = $conn->prepare($sql);
if ($tipos !== '') $stmt->bind_param($tipos, ...$params);
$stmt->execute();
$clientes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Dietistas
$stmt = $conn->prepare("SELECT id, nombre_completo, (SELECT COUNT(*) FROM usuarios c WHERE c.id_dietista=u.id AND c.activo=1) AS n
                        FROM usuarios u WHERE rol='dietista' AND activo=1 ORDER BY nombre_completo");
$stmt->execute();
$dietistas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$base   = '../../';
$active = 'asignaciones';
$titulo = 'Asignaciones';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main class="page">

  <?php if ($ok):    ?><div class="alert alert-success" role="status"><?= e($ok) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"  role="alert"><?= e($error) ?></div><?php endif; ?>

  <!-- Resumen de dietistas y su carga -->
  <section class="card">
    <h3 class="card-title">👨‍⚕️ Dietistas y carga de clientes</h3>
    <?php if (!$dietistas): ?>
      <p class="text-muted">No hay dietistas activos.</p>
    <?php else: ?>
      <ul class="dt-clients" role="list">
        <?php foreach ($dietistas as $d): ?>
          <li class="dt-client">
            <div class="dt-client-link">
              <div class="chats-avatar"><?= e(mb_strtoupper(mb_substr($d['nombre_completo'], 0, 1, 'UTF-8'), 'UTF-8')) ?></div>
              <div class="dt-client-info">
                <div class="dt-client-name"><?= e($d['nombre_completo']) ?></div>
                <div class="dt-client-meta">
                  <span class="dt-pill dt-pill--ok"><?= (int)$d['n'] ?> cliente<?= (int)$d['n'] === 1 ? '' : 's' ?></span>
                </div>
              </div>
              <a class="btn btn-outline btn-mini" href="?filtro=por_dietista&dietista=<?= (int)$d['id'] ?>">Ver</a>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <!-- Filtros -->
  <form method="get" class="card">
    <div class="grid-2">
      <label class="field">
        <span class="field-label">Buscar cliente</span>
        <input class="field-input" type="search" name="q" placeholder="Nombre o email" value="<?= e($q) ?>">
      </label>
      <label class="field">
        <span class="field-label">Filtro</span>
        <select class="field-select" name="filtro" onchange="this.form.submit()">
          <option value="todos"         <?= $filtro==='todos'         ? 'selected' : '' ?>>Todos</option>
          <option value="sin_dietista"  <?= $filtro==='sin_dietista'  ? 'selected' : '' ?>>Sin dietista</option>
          <option value="por_dietista"  <?= $filtro==='por_dietista'  ? 'selected' : '' ?>>Por dietista</option>
        </select>
      </label>
    </div>

    <?php if ($filtro === 'por_dietista'): ?>
      <label class="field">
        <span class="field-label">Dietista</span>
        <select class="field-select" name="dietista" onchange="this.form.submit()">
          <option value="0">— Elegir —</option>
          <?php foreach ($dietistas as $d): ?>
            <option value="<?= (int)$d['id'] ?>" <?= $dietistaF === (int)$d['id'] ? 'selected' : '' ?>>
              <?= e($d['nombre_completo']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
    <?php endif; ?>

    <button type="submit" class="btn btn-primary btn-block">Filtrar</button>
  </form>

  <!-- Asignación en lote -->
  <form method="post" class="card">
    <h3 class="card-title">📦 Asignar varios clientes a la vez</h3>
    <p class="text-soft">Marca clientes con la casilla a la izquierda y elige el dietista destino abajo.</p>
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="accion" value="asignar_lote">

    <section class="card-inner">
      <?php if (!$clientes): ?>
        <p class="text-muted">No hay clientes con esos filtros.</p>
      <?php else: ?>
        <ul class="dt-clients" role="list">
          <?php foreach ($clientes as $c): ?>
            <li class="dt-client">
              <label class="dt-client-link as-row">
                <input type="checkbox" name="clientes[]" value="<?= (int)$c['id'] ?>">
                <div class="chats-avatar">
                  <?= e(mb_strtoupper(mb_substr($c['nombre_completo'], 0, 1, 'UTF-8'), 'UTF-8')) ?>
                </div>
                <div class="dt-client-info">
                  <div class="dt-client-name"><?= e($c['nombre_completo']) ?></div>
                  <div class="dt-client-meta">
                    <span class="text-muted"><?= e($c['email']) ?></span>
                    <?php if ($c['dietista_nombre']): ?>
                      <span class="dt-pill dt-pill--ok"><?= e($c['dietista_nombre']) ?></span>
                    <?php else: ?>
                      <span class="dt-pill dt-pill--warn">Sin dietista</span>
                    <?php endif; ?>
                  </div>
                </div>
              </label>

              <!-- Asignación individual por fila -->
              <form method="post" class="inline-form" style-disabled="">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="accion" value="asignar">
                <input type="hidden" name="id_cliente" value="<?= (int)$c['id'] ?>">
                <select class="field-select btn-mini" name="id_dietista" onchange="this.form.submit()">
                  <option value="0">— Sin dietista —</option>
                  <?php foreach ($dietistas as $d): ?>
                    <option value="<?= (int)$d['id'] ?>" <?= (int)$c['id_dietista'] === (int)$d['id'] ? 'selected' : '' ?>>
                      <?= e($d['nombre_completo']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <label class="field">
      <span class="field-label">Dietista destino para los marcados</span>
      <select class="field-select" name="id_dietista">
        <option value="0">— Sin dietista —</option>
        <?php foreach ($dietistas as $d): ?>
          <option value="<?= (int)$d['id'] ?>"><?= e($d['nombre_completo']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <button type="submit" class="btn btn-primary btn-block">Asignar a los marcados</button>
  </form>

</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>