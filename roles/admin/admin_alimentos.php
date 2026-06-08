<?php
require_once __DIR__ . '/../../includes/conexion.php';
requiere_rol('admin', '../../');

$uid   = usuario_id();
$ok    = '';
$error = '';

// ============================================================
// POST: crear/editar/aprobar/borrar
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $error = 'Sesión expirada.';
    } else {
        $accion = $_POST['accion'] ?? '';

        if ($accion === 'guardar') {
            $idEdit  = (int)($_POST['id'] ?? 0);
            $nombre  = trim($_POST['nombre'] ?? '');
            $marca   = trim($_POST['marca'] ?? '');
            $racion  = (float)($_POST['racion_base_gr'] ?? 100);
            $kcal    = (float)($_POST['kcal'] ?? 0);
            $prot    = (float)($_POST['proteinas'] ?? 0);
            $carb    = (float)($_POST['carbos'] ?? 0);
            $gras    = (float)($_POST['grasas'] ?? 0);
            $aprob   = isset($_POST['aprobado_global']) ? 1 : 0;
            if ($marca === '') $marca = null;
            if ($racion <= 0)  $racion = 100;

            if ($nombre === '') {
                $error = 'Nombre obligatorio.';
            } else {
                if ($idEdit > 0) {
                    $stmt = $conn->prepare(
                        "UPDATE alimentos SET nombre=?, marca=?, racion_base_gr=?, kcal=?, proteinas=?, carbos=?, grasas=?, aprobado_global=?
                         WHERE id=?"
                    );
                    $stmt->bind_param('ssddddddi', $nombre, $marca, $racion, $kcal, $prot, $carb, $gras, $aprob, $idEdit);
                    if ($stmt->execute()) {
                        log_admin($conn, $uid, 'EDITAR_ALIMENTO', "El Admin editó el alimento ID #{$idEdit}: {$nombre}");
                        $ok = 'Alimento actualizado.';
                    } else $error = 'No se pudo actualizar.';
                    $stmt->close();
                } else {
                    // Crear como global (id_dietista NULL)
                    $stmt = $conn->prepare(
                        "INSERT INTO alimentos (id_dietista, nombre, marca, racion_base_gr, kcal, proteinas, carbos, grasas, aprobado_global)
                         VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                    $stmt->bind_param('ssdddddi', $nombre, $marca, $racion, $kcal, $prot, $carb, $gras, $aprob);
                    if ($stmt->execute()) {
                        log_admin($conn, $uid, 'CREAR_ALIMENTO', "El Admin creó el alimento: {$nombre}");
                        $ok = 'Alimento creado.';
                    } else $error = 'No se pudo crear.';
                    $stmt->close();
                }
            }
        }
        elseif ($accion === 'toggle_global') {
            $id = (int)$_POST['id'];
            $stmt = $conn->prepare("UPDATE alimentos SET aprobado_global = 1 - aprobado_global WHERE id=?");
            $stmt->bind_param('i', $id);
            if ($stmt->execute()) {
                log_admin($conn, $uid, 'TOGGLE_ALIMENTO', "El Admin cambió el estado global del alimento ID #{$id}");
                $ok = 'Estado global cambiado.';
            }
            $stmt->close();
        }
        elseif ($accion === 'borrar') {
            $idDel = (int)$_POST['id'];
            // No borrar si está en uso
            $stmt = $conn->prepare("SELECT COUNT(*) c FROM dieta_alimentos WHERE id_alimento=?");
            $stmt->bind_param('i', $idDel);
            $stmt->execute();
            $enUso = (int)$stmt->get_result()->fetch_assoc()['c'];
            $stmt->close();
            if ($enUso > 0) {
                $error = 'No se puede borrar: está en uso en una o más dietas.';
            } else {
                $stmt = $conn->prepare("DELETE FROM alimentos WHERE id=?");
                $stmt->bind_param('i', $idDel);
                if ($stmt->execute()) {
                    log_admin($conn, $uid, 'BORRAR_ALIMENTO', "El Admin borró el alimento ID #{$idDel}");
                    $ok = 'Alimento borrado.';
                } else $error = 'No se pudo borrar.';
                $stmt->close();
            }
        }
    }
}

// --- Edición ---
$editando = null;
if (isset($_GET['edit'])) {
    $idE = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM alimentos WHERE id=?");
    $stmt->bind_param('i', $idE);
    $stmt->execute();
    $editando = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// --- Filtros ---
$q       = trim($_GET['q'] ?? '');
$filtro  = $_GET['filtro'] ?? 'todos'; // todos | globales | pendientes | dietista

$where = "1=1"; $params = []; $tipos = '';

if ($filtro === 'globales')         { $where .= " AND a.aprobado_global=1 AND a.id_dietista IS NULL"; }
elseif ($filtro === 'pendientes')   { $where .= " AND a.aprobado_global=0"; }
elseif ($filtro === 'dietista')     { $where .= " AND a.id_dietista IS NOT NULL"; }

if ($q !== '') {
    $where .= " AND (a.nombre LIKE ? OR a.marca LIKE ?)";
    $like = '%' . $q . '%'; $params[] = $like; $params[] = $like; $tipos .= 'ss';
}

$sql = "SELECT a.*, u.nombre_completo AS dietista
        FROM alimentos a
        LEFT JOIN usuarios u ON u.id = a.id_dietista
        WHERE $where
        ORDER BY a.aprobado_global DESC, a.nombre ASC
        LIMIT 500";
$stmt = $conn->prepare($sql);
if ($tipos !== '') $stmt->bind_param($tipos, ...$params);
$stmt->execute();
$alimentos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

function val_al(array $arr, string $k, $d=''): string {
    return isset($arr[$k]) && $arr[$k] !== null ? (string)$arr[$k] : (string)$d;
}

$base   = '../../';
$active = 'alimentos';
$titulo = 'Alimentos (admin)';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main class="page">

  <?php if ($ok):    ?><div class="alert alert-success" role="status"><?= e($ok) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"  role="alert"><?= e($error) ?></div><?php endif; ?>

  <!-- Form crear/editar -->
  <form method="post" class="card">
    <h2 class="card-title"><?= $editando ? '✏️ Editar alimento' : '➕ Nuevo alimento global' ?></h2>
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="accion" value="guardar">
    <?php if ($editando): ?><input type="hidden" name="id" value="<?= (int)$editando['id'] ?>"><?php endif; ?>

    <div class="grid-2">
      <label class="field">
        <span class="field-label">Nombre</span>
        <input class="field-input" type="text" name="nombre" required value="<?= e(val_al($editando ?: [], 'nombre')) ?>">
      </label>
      <label class="field">
        <span class="field-label">Marca</span>
        <input class="field-input" type="text" name="marca" value="<?= e(val_al($editando ?: [], 'marca')) ?>">
      </label>
    </div>

    <label class="field">
      <span class="field-label">Ración base (g)</span>
      <input class="field-input" type="number" step="0.01" min="1" name="racion_base_gr"
             value="<?= e(val_al($editando ?: [], 'racion_base_gr', '100')) ?>">
    </label>

    <div class="grid-2">
      <label class="field"><span class="field-label">Kcal</span>
        <input class="field-input" type="number" step="0.01" min="0" name="kcal" required value="<?= e(val_al($editando ?: [], 'kcal')) ?>"></label>
      <label class="field"><span class="field-label">Prot (g)</span>
        <input class="field-input" type="number" step="0.01" min="0" name="proteinas" required value="<?= e(val_al($editando ?: [], 'proteinas')) ?>"></label>
      <label class="field"><span class="field-label">Carbos (g)</span>
        <input class="field-input" type="number" step="0.01" min="0" name="carbos" required value="<?= e(val_al($editando ?: [], 'carbos')) ?>"></label>
      <label class="field"><span class="field-label">Grasas (g)</span>
        <input class="field-input" type="number" step="0.01" min="0" name="grasas" required value="<?= e(val_al($editando ?: [], 'grasas')) ?>"></label>
    </div>

    <label class="field">
      <span class="field-label">
        <input type="checkbox" name="aprobado_global" value="1" <?= !$editando || (int)$editando['aprobado_global'] === 1 ? 'checked' : '' ?>>
        Aprobado como alimento global
      </span>
    </label>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary btn-block"><?= $editando ? 'Actualizar' : 'Crear' ?></button>
      <?php if ($editando): ?>
        <a class="btn btn-outline btn-block" href="admin_alimentos.php">Cancelar</a>
      <?php endif; ?>
    </div>
  </form>

  <!-- Filtros -->
  <form method="get" class="card">
    <div class="grid-2">
      <label class="field">
        <span class="field-label">Buscar</span>
        <input class="field-input" type="search" name="q" placeholder="Nombre o marca" value="<?= e($q) ?>">
      </label>
      <label class="field">
        <span class="field-label">Filtro</span>
        <select class="field-select" name="filtro" onchange="this.form.submit()">
          <option value="todos"      <?= $filtro==='todos'      ? 'selected' : '' ?>>Todos</option>
          <option value="globales"   <?= $filtro==='globales'   ? 'selected' : '' ?>>Solo globales</option>
          <option value="pendientes" <?= $filtro==='pendientes' ? 'selected' : '' ?>>No aprobados</option>
          <option value="dietista"   <?= $filtro==='dietista'   ? 'selected' : '' ?>>Creados por dietistas</option>
        </select>
      </label>
    </div>
    <button type="submit" class="btn btn-primary btn-block">Filtrar</button>
  </form>

  <!-- Listado -->
  <section class="card">
    <h3 class="card-title">📋 <?= count($alimentos) ?> resultado<?= count($alimentos) === 1 ? '' : 's' ?></h3>
    <?php if (!$alimentos): ?>
      <p class="text-muted">Sin resultados.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Nombre</th>
              <th>Marca</th>
              <th>Kcal</th>
              <th>P</th>
              <th>C</th>
              <th>G</th>
              <th>Creador</th>
              <th>Global</th>
              <th class="td-actions"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($alimentos as $a): ?>
              <tr>
                <td><?= e($a['nombre']) ?></td>
                <td><?= e($a['marca'] ?? '—') ?></td>
                <td><?= e(rtrim(rtrim($a['kcal'], '0'), '.')) ?></td>
                <td><?= e(rtrim(rtrim($a['proteinas'], '0'), '.')) ?></td>
                <td><?= e(rtrim(rtrim($a['carbos'], '0'), '.')) ?></td>
                <td><?= e(rtrim(rtrim($a['grasas'], '0'), '.')) ?></td>
                <td><?= $a['id_dietista'] ? e($a['dietista']) : '<span class="text-muted">Sistema</span>' ?></td>
                <td>
                  <form method="post" class="inline-form">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="accion" value="toggle_global">
                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <button type="submit" class="btn btn-ghost btn-mini" title="Alternar global">
                      <?= (int)$a['aprobado_global'] ? '✅' : '⬜' ?>
                    </button>
                  </form>
                </td>
                <td class="td-actions">
                  <a class="btn btn-ghost btn-mini" href="?edit=<?= (int)$a['id'] ?>">✏️</a>
                  <form method="post" class="inline-form" onsubmit="return confirm('¿Borrar?');">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="accion" value="borrar">
                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <button type="submit" class="btn btn-ghost btn-mini">🗑️</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>