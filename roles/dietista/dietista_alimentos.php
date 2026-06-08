<?php
require_once __DIR__ . '/../../includes/conexion.php';
requiere_rol('dietista', '../../');

$uid   = usuario_id();
$ok    = '';
$error = '';

// ============================================================
// POST: crear / editar / borrar
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $error = 'Sesión expirada.';
    } else {
        $accion = $_POST['accion'] ?? '';

        if ($accion === 'guardar') {
            $idEdit = (int)($_POST['id'] ?? 0);
            $nombre = trim($_POST['nombre'] ?? '');
            $marca  = trim($_POST['marca'] ?? '');
            $racion = (float)($_POST['racion_base_gr'] ?? 100);
            $kcal   = (float)($_POST['kcal'] ?? 0);
            $prot   = (float)($_POST['proteinas'] ?? 0);
            $carb   = (float)($_POST['carbos'] ?? 0);
            $gras   = (float)($_POST['grasas'] ?? 0);
            if ($marca === '') $marca = null;
            if ($racion <= 0) $racion = 100;

            if ($nombre === '') {
                $error = 'El nombre es obligatorio.';
            } else {
                if ($idEdit > 0) {
                    // Solo puede editar los propios
                    $stmt = $conn->prepare(
                        "UPDATE alimentos SET nombre=?, marca=?, racion_base_gr=?, kcal=?, proteinas=?, carbos=?, grasas=?
                         WHERE id=? AND id_dietista=?"
                    );
                    $stmt->bind_param('ssdddddii', $nombre, $marca, $racion, $kcal, $prot, $carb, $gras, $idEdit, $uid);
                    $ok = $stmt->execute() && $stmt->affected_rows >= 0 ? 'Alimento actualizado.' : 'No se pudo actualizar.';
                    $stmt->close();
                } else {
                    $stmt = $conn->prepare(
                        "INSERT INTO alimentos (id_dietista, nombre, marca, racion_base_gr, kcal, proteinas, carbos, grasas, aprobado_global)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)"
                    );
                    $stmt->bind_param('issddddd', $uid, $nombre, $marca, $racion, $kcal, $prot, $carb, $gras);
                    $ok = $stmt->execute() ? 'Alimento creado.' : 'No se pudo crear.';
                    $stmt->close();
                }
            }
        }
        elseif ($accion === 'borrar') {
            $idDel = (int)($_POST['id'] ?? 0);
            // Verificar que es del dietista y no está en uso
            $stmt = $conn->prepare("SELECT COUNT(*) c FROM dieta_alimentos WHERE id_alimento=?");
            $stmt->bind_param('i', $idDel);
            $stmt->execute();
            $enUso = (int)$stmt->get_result()->fetch_assoc()['c'];
            $stmt->close();

            if ($enUso > 0) {
                $error = 'No se puede borrar: el alimento está en uso en una o más dietas.';
            } else {
                $stmt = $conn->prepare("DELETE FROM alimentos WHERE id=? AND id_dietista=?");
                $stmt->bind_param('ii', $idDel, $uid);
                $ok = $stmt->execute() && $stmt->affected_rows > 0 ? 'Alimento borrado.' : 'No se pudo borrar (¿es un alimento global?).';
                $stmt->close();
            }
        }
    }
}

// ============================================================
// Edición y búsqueda
// ============================================================
$editando = null;
if (isset($_GET['edit'])) {
    $idE = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM alimentos WHERE id=? AND id_dietista=?");
    $stmt->bind_param('ii', $idE, $uid);
    $stmt->execute();
    $editando = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$q = trim($_GET['q'] ?? '');
$filtro = $_GET['filtro'] ?? 'todos';

$where  = "((a.aprobado_global=1 AND a.id_dietista IS NULL) OR a.id_dietista = ?)";
$params = [$uid];
$tipos  = 'i';

if ($filtro === 'mios')    { $where = "a.id_dietista = ?"; }
elseif ($filtro === 'globales') { $where = "a.aprobado_global=1 AND a.id_dietista IS NULL"; $params = []; $tipos = ''; }

if ($q !== '') {
    $where .= " AND (a.nombre LIKE ? OR a.marca LIKE ?)";
    $like = '%' . $q . '%';
    $params[] = $like; $params[] = $like;
    $tipos .= 'ss';
}

$sql = "SELECT a.* FROM alimentos a WHERE $where ORDER BY a.nombre ASC LIMIT 300";
$stmt = $conn->prepare($sql);
if ($tipos !== '') $stmt->bind_param($tipos, ...$params);
$stmt->execute();
$alimentos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$val = function (string $k) use ($editando) {
    return $editando && isset($editando[$k]) ? (string)$editando[$k] : '';
};

$base   = '../../';
$active = 'alimentos';
$titulo = 'Alimentos';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main class="page">

  <?php if ($ok):    ?><div class="alert alert-success" role="status"><?= e($ok) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"  role="alert"><?= e($error) ?></div><?php endif; ?>

  <!-- FORM nuevo/editar -->
  <form method="post" class="card">
    <h2 class="card-title"><?= $editando ? '✏️ Editar alimento' : '➕ Nuevo alimento' ?></h2>
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="accion" value="guardar">
    <?php if ($editando): ?><input type="hidden" name="id" value="<?= (int)$editando['id'] ?>"><?php endif; ?>

    <div class="grid-2">
      <label class="field">
        <span class="field-label">Nombre</span>
        <input class="field-input" type="text" name="nombre" required maxlength="150" value="<?= e($val('nombre')) ?>">
      </label>
      <label class="field">
        <span class="field-label">Marca (opcional)</span>
        <input class="field-input" type="text" name="marca" maxlength="100" value="<?= e($val('marca')) ?>">
      </label>
    </div>

    <label class="field">
      <span class="field-label">Ración base (gramos a los que están referidos los valores nutricionales)</span>
      <input class="field-input" type="number" step="0.01" min="1" name="racion_base_gr" value="<?= e($val('racion_base_gr')) ?: '100' ?>">
    </label>

    <div class="grid-2">
      <label class="field">
        <span class="field-label">Kcal</span>
        <input class="field-input" type="number" step="0.01" min="0" name="kcal" inputmode="decimal" required value="<?= e($val('kcal')) ?>">
      </label>
      <label class="field">
        <span class="field-label">Proteínas (g)</span>
        <input class="field-input" type="number" step="0.01" min="0" name="proteinas" inputmode="decimal" required value="<?= e($val('proteinas')) ?>">
      </label>
      <label class="field">
        <span class="field-label">Carbos (g)</span>
        <input class="field-input" type="number" step="0.01" min="0" name="carbos" inputmode="decimal" required value="<?= e($val('carbos')) ?>">
      </label>
      <label class="field">
        <span class="field-label">Grasas (g)</span>
        <input class="field-input" type="number" step="0.01" min="0" name="grasas" inputmode="decimal" required value="<?= e($val('grasas')) ?>">
      </label>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary btn-block"><?= $editando ? 'Actualizar' : 'Crear alimento' ?></button>
      <?php if ($editando): ?>
        <a class="btn btn-outline btn-block" href="dietista_alimentos.php">Cancelar</a>
      <?php endif; ?>
    </div>
  </form>

  <!-- BUSCADOR y filtros -->
  <form class="card" method="get">
    <div class="grid-2">
      <label class="field">
        <span class="field-label">Buscar</span>
        <input class="field-input" type="search" name="q" placeholder="Nombre o marca" value="<?= e($q) ?>">
      </label>
      <label class="field">
        <span class="field-label">Mostrar</span>
        <select class="field-select" name="filtro">
          <option value="todos"    <?= $filtro==='todos'    ? 'selected' : '' ?>>Todos</option>
          <option value="mios"     <?= $filtro==='mios'     ? 'selected' : '' ?>>Mis alimentos</option>
          <option value="globales" <?= $filtro==='globales' ? 'selected' : '' ?>>Globales</option>
        </select>
      </label>
    </div>
    <button type="submit" class="btn btn-primary btn-block">Filtrar</button>
  </form>

  <!-- LISTADO -->
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
              <th>Por</th>
              <th class="td-actions"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($alimentos as $a):
              $esMio = ((int)$a['id_dietista'] === $uid); ?>
              <tr>
                <td><?= e($a['nombre']) ?></td>
                <td><?= e($a['marca'] ?? '—') ?></td>
                <td><?= e(rtrim(rtrim((string)$a['kcal'], '0'), '.')) ?></td>
                <td><?= e(rtrim(rtrim((string)$a['proteinas'], '0'), '.')) ?></td>
                <td><?= e(rtrim(rtrim((string)$a['carbos'], '0'), '.')) ?></td>
                <td><?= e(rtrim(rtrim((string)$a['grasas'], '0'), '.')) ?></td>
                <td><?= e(rtrim(rtrim((string)$a['racion_base_gr'], '0'), '.')) ?>g</td>
                <td class="td-actions">
                  <?php if ($esMio): ?>
                    <a class="btn btn-ghost btn-mini" href="?edit=<?= (int)$a['id'] ?>">✏️</a>
                    <form method="post" class="inline-form" onsubmit="return confirm('¿Borrar este alimento?');">
                      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="accion" value="borrar">
                      <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                      <button type="submit" class="btn btn-ghost btn-mini">🗑️</button>
                    </form>
                  <?php else: ?>
                    <span class="dt-pill dt-pill--ok">Global</span>
                  <?php endif; ?>
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