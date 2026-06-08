<?php
require_once __DIR__ . '/../../includes/conexion.php';
requiere_rol('cliente', '../../');

$uid    = usuario_id();
$ok     = '';
$error  = '';

$campos = [
    'cintura'    => 'Cintura',
    'cadera'     => 'Cadera',
    'pecho'      => 'Pecho',
    'cuello'     => 'Cuello',
    'hombros'    => 'Hombros',
    'brazo_izq'  => 'Brazo izquierdo',
    'brazo_der'  => 'Brazo derecho',
    'muslo_izq'  => 'Muslo izquierdo',
    'muslo_der'  => 'Muslo derecho',
    'pantorrilla'=> 'Pantorrilla',
];

// --- POST: guardar / borrar ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $error = 'Sesión expirada. Recarga la página.';
    } else {
        $accion = $_POST['accion'] ?? '';

        if ($accion === 'guardar') {
            $idEdit = (int)($_POST['id'] ?? 0);
            $fecha  = $_POST['fecha'] ?? date('Y-m-d');
            $notas  = trim($_POST['notas'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) $fecha = date('Y-m-d');

            $valores = [];
            foreach ($campos as $col => $_) {
                $v = $_POST[$col] ?? '';
                $valores[$col] = ($v === '' || $v === null) ? null : (float)$v;
            }

            if ($idEdit > 0) {
                // verificar propiedad
                $stmt = $conn->prepare("SELECT id FROM medidas_corporales WHERE id = ? AND id_cliente = ?");
                $stmt->bind_param('ii', $idEdit, $uid);
                $stmt->execute();
                $existe = (bool)$stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$existe) {
                    $error = 'Registro no encontrado.';
                } else {
                    $stmt = $conn->prepare(
                        "UPDATE medidas_corporales SET fecha=?, cintura=?, cadera=?, pecho=?, cuello=?,
                            hombros=?, brazo_izq=?, brazo_der=?, muslo_izq=?, muslo_der=?, pantorrilla=?, notas=?
                         WHERE id=? AND id_cliente=?"
                    );
                    $stmt->bind_param(
                        'sddddddddddsii',
                        $fecha,
                        $valores['cintura'], $valores['cadera'], $valores['pecho'], $valores['cuello'],
                        $valores['hombros'], $valores['brazo_izq'], $valores['brazo_der'],
                        $valores['muslo_izq'], $valores['muslo_der'], $valores['pantorrilla'],
                        $notas, $idEdit, $uid
                    );
                    $ok = $stmt->execute() ? 'Medida actualizada.' : 'No se pudo actualizar.';
                    $stmt->close();
                }
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO medidas_corporales
                       (id_cliente, fecha, cintura, cadera, pecho, cuello, hombros,
                        brazo_izq, brazo_der, muslo_izq, muslo_der, pantorrilla, notas)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
                );
                $stmt->bind_param(
                    'isddddddddddss',
                    $uid, $fecha,
                    $valores['cintura'], $valores['cadera'], $valores['pecho'], $valores['cuello'],
                    $valores['hombros'], $valores['brazo_izq'], $valores['brazo_der'],
                    $valores['muslo_izq'], $valores['muslo_der'], $valores['pantorrilla'],
                    $notas
                );
                // OJO: tipos correctos (no son 13 caracteres exactos arriba — regenero limpio):
                $stmt->close();

                $stmt = $conn->prepare(
                    "INSERT INTO medidas_corporales
                       (id_cliente, fecha, cintura, cadera, pecho, cuello, hombros,
                        brazo_izq, brazo_der, muslo_izq, muslo_der, pantorrilla, notas)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
                );
                $stmt->bind_param(
                    'isdddddddddds',
                    $uid, $fecha,
                    $valores['cintura'], $valores['cadera'], $valores['pecho'], $valores['cuello'],
                    $valores['hombros'], $valores['brazo_izq'], $valores['brazo_der'],
                    $valores['muslo_izq'], $valores['muslo_der'], $valores['pantorrilla'],
                    $notas
                );
                $ok = $stmt->execute() ? 'Medida guardada.' : 'No se pudo guardar.';
                $stmt->close();
            }
        }
        elseif ($accion === 'borrar') {
            $idDel = (int)($_POST['id'] ?? 0);
            $stmt = $conn->prepare("DELETE FROM medidas_corporales WHERE id = ? AND id_cliente = ?");
            $stmt->bind_param('ii', $idDel, $uid);
            $ok = $stmt->execute() ? 'Registro borrado.' : 'No se pudo borrar.';
            $stmt->close();
        }
    }
}

// --- Edición ---
$editando = null;
if (isset($_GET['edit'])) {
    $idE = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM medidas_corporales WHERE id = ? AND id_cliente = ?");
    $stmt->bind_param('ii', $idE, $uid);
    $stmt->execute();
    $editando = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// --- Histórico ---
$stmt = $conn->prepare(
    "SELECT * FROM medidas_corporales WHERE id_cliente = ? ORDER BY fecha DESC, id DESC"
);
$stmt->bind_param('i', $uid);
$stmt->execute();
$historico = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$base   = '../../';
$active = 'medidas';
$titulo = 'Medidas';
include __DIR__ . '/../../includes/sidebar.php';

$val = function (string $col) use ($editando) {
    return $editando && isset($editando[$col]) && $editando[$col] !== null ? (string)$editando[$col] : '';
};
?>
<main class="page">

  <?php if ($ok):    ?><div class="alert alert-success" role="status"><?= e($ok) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"  role="alert"><?= e($error) ?></div><?php endif; ?>

  <!-- FORM nueva / editar -->
  <form method="post" class="card">
    <h2 class="card-title"><?= $editando ? '✏️ Editar medida' : '➕ Nueva medida' ?></h2>
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="accion" value="guardar">
    <?php if ($editando): ?>
      <input type="hidden" name="id" value="<?= (int)$editando['id'] ?>">
    <?php endif; ?>

    <label class="field">
      <span class="field-label">Fecha</span>
      <input class="field-input" type="date" name="fecha"
             value="<?= e($editando['fecha'] ?? date('Y-m-d')) ?>" required>
    </label>

    <div class="medidas-grid">
      <?php foreach ($campos as $col => $label): ?>
        <label class="field">
          <span class="field-label"><?= e($label) ?> <span class="text-muted">(cm)</span></span>
          <input class="field-input" type="number" step="0.1" min="0" max="300"
                 name="<?= e($col) ?>" inputmode="decimal" value="<?= e($val($col)) ?>">
        </label>
      <?php endforeach; ?>
    </div>

    <label class="field">
      <span class="field-label">Notas</span>
      <textarea class="field-textarea" name="notas" maxlength="500"><?= e($val('notas')) ?></textarea>
    </label>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary btn-block">
        <?= $editando ? 'Actualizar medida' : 'Guardar medida' ?>
      </button>
      <?php if ($editando): ?>
        <a class="btn btn-outline btn-block" href="cliente_medidas.php">Cancelar</a>
      <?php endif; ?>
    </div>
  </form>

  <!-- HISTÓRICO -->
  <section class="card">
    <h2 class="card-title">📋 Histórico</h2>
    <?php if (!$historico): ?>
      <p class="text-muted">Aún no has registrado medidas.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Fecha</th>
              <?php foreach ($campos as $col => $label): ?>
                <th><?= e(short_label($label)) ?></th>
              <?php endforeach; ?>
              <th class="td-actions"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($historico as $r): ?>
              <tr>
                <td><strong><?= e(date('d/m/Y', strtotime($r['fecha']))) ?></strong></td>
                <?php foreach ($campos as $col => $_):
                  $v = $r[$col] !== null ? rtrim(rtrim((string)$r[$col], '0'), '.') : '—'; ?>
                  <td><?= e($v) ?></td>
                <?php endforeach; ?>
                <td class="td-actions">
                  <a class="btn btn-ghost btn-mini" href="?edit=<?= (int)$r['id'] ?>" aria-label="Editar">✏️</a>
                  <form method="post" class="inline-form" onsubmit="return confirm('¿Borrar esta medida?');">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="accion" value="borrar">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button type="submit" class="btn btn-ghost btn-mini" aria-label="Borrar">🗑️</button>
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

<?php
function short_label(string $s): string {
    return str_replace(['izquierdo','derecho'], ['izq.','der.'], $s);
}
include __DIR__ . '/../../includes/footer.php';
?>