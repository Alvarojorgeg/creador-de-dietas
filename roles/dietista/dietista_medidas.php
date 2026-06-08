<?php
require_once __DIR__ . '/../../includes/conexion.php';
requiere_rol('dietista', '../../');

$uid   = usuario_id();
$ok    = '';
$error = '';
$idC   = isset($_GET['id_cliente']) ? (int)$_GET['id_cliente'] : 0;

$campos = [
    'cintura'    => 'Cintura',
    'cadera'     => 'Cadera',
    'pecho'      => 'Pecho',
    'cuello'     => 'Cuello',
    'hombros'    => 'Hombros',
    'brazo_izq'  => 'Brazo izq.',
    'brazo_der'  => 'Brazo der.',
    'muslo_izq'  => 'Muslo izq.',
    'muslo_der'  => 'Muslo der.',
    'pantorrilla'=> 'Pantorrilla',
];

// Verificar propiedad del cliente
$cliente = null;
if ($idC > 0) {
    $stmt = $conn->prepare("SELECT id, nombre_completo FROM usuarios WHERE id=? AND id_dietista=? AND rol='cliente' AND activo=1");
    $stmt->bind_param('ii', $idC, $uid);
    $stmt->execute();
    $cliente = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$cliente) { header('Location: dietista_medidas.php'); exit; }
}

// ============================================================
// MODO LISTADO
// ============================================================
if (!$cliente) {
    $stmt = $conn->prepare(
        "SELECT u.id, u.nombre_completo,
                (SELECT fecha FROM medidas_corporales WHERE id_cliente=u.id ORDER BY fecha DESC LIMIT 1) AS ultima
         FROM usuarios u
         WHERE u.rol='cliente' AND u.id_dietista=? AND u.activo=1
         ORDER BY u.nombre_completo"
    );
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $lista = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $base   = '../../';
    $active = 'medidas';
    $titulo = 'Medidas de clientes';
    include __DIR__ . '/../../includes/sidebar.php';
    ?>
    <main class="page">
      <section class="card">
        <h2 class="card-title">👥 Selecciona un cliente</h2>
        <?php if (!$lista): ?>
          <p class="text-muted">No tienes clientes asignados.</p>
        <?php else: ?>
          <ul class="dt-clients" role="list">
            <?php foreach ($lista as $c): ?>
              <li class="dt-client">
                <a class="dt-client-link" href="?id_cliente=<?= (int)$c['id'] ?>">
                  <div class="chats-avatar">
                    <?= e(mb_strtoupper(mb_substr($c['nombre_completo'], 0, 1, 'UTF-8'), 'UTF-8')) ?>
                  </div>
                  <div class="dt-client-info">
                    <div class="dt-client-name"><?= e($c['nombre_completo']) ?></div>
                    <div class="dt-client-meta">
                      <?php if ($c['ultima']): ?>
                        <span class="text-muted">Última: <?= e(date('d/m/Y', strtotime($c['ultima']))) ?></span>
                      <?php else: ?>
                        <span class="dt-pill dt-pill--warn">Sin medidas</span>
                      <?php endif; ?>
                    </div>
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

// ============================================================
// POST: guardar / borrar para este cliente
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $error = 'Sesión expirada.';
    } else {
        $accion = $_POST['accion'] ?? '';

        if ($accion === 'guardar') {
            $idEdit = (int)($_POST['id'] ?? 0);
            $fecha  = $_POST['fecha'] ?? date('Y-m-d');
            $notas  = trim($_POST['notas'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) $fecha = date('Y-m-d');

            $v = [];
            foreach ($campos as $col => $_) {
                $val = $_POST[$col] ?? '';
                $v[$col] = ($val === '' || $val === null) ? null : (float)$val;
            }

            if ($idEdit > 0) {
                $stmt = $conn->prepare(
                    "UPDATE medidas_corporales SET fecha=?, cintura=?, cadera=?, pecho=?, cuello=?,
                        hombros=?, brazo_izq=?, brazo_der=?, muslo_izq=?, muslo_der=?, pantorrilla=?, notas=?
                     WHERE id=? AND id_cliente=?"
                );
                $stmt->bind_param('sddddddddddsii',
                    $fecha, $v['cintura'], $v['cadera'], $v['pecho'], $v['cuello'],
                    $v['hombros'], $v['brazo_izq'], $v['brazo_der'],
                    $v['muslo_izq'], $v['muslo_der'], $v['pantorrilla'],
                    $notas, $idEdit, $idC);
                $ok = $stmt->execute() ? 'Medida actualizada.' : 'No se pudo actualizar.';
                $stmt->close();
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO medidas_corporales
                       (id_cliente, fecha, cintura, cadera, pecho, cuello, hombros,
                        brazo_izq, brazo_der, muslo_izq, muslo_der, pantorrilla, notas)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
                );
                $stmt->bind_param('isdddddddddds',
                    $idC, $fecha,
                    $v['cintura'], $v['cadera'], $v['pecho'], $v['cuello'],
                    $v['hombros'], $v['brazo_izq'], $v['brazo_der'],
                    $v['muslo_izq'], $v['muslo_der'], $v['pantorrilla'], $notas);
                $ok = $stmt->execute() ? 'Medida guardada.' : 'No se pudo guardar.';
                $stmt->close();
            }
        }
        elseif ($accion === 'borrar') {
            $idDel = (int)($_POST['id'] ?? 0);
            $stmt = $conn->prepare("DELETE FROM medidas_corporales WHERE id=? AND id_cliente=?");
            $stmt->bind_param('ii', $idDel, $idC);
            $ok = $stmt->execute() ? 'Registro borrado.' : 'No se pudo borrar.';
            $stmt->close();
        }
    }
}

// --- Edición ---
$editando = null;
if (isset($_GET['edit'])) {
    $idE = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM medidas_corporales WHERE id=? AND id_cliente=?");
    $stmt->bind_param('ii', $idE, $idC);
    $stmt->execute();
    $editando = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// --- Histórico ---
$stmt = $conn->prepare("SELECT * FROM medidas_corporales WHERE id_cliente=? ORDER BY fecha DESC, id DESC");
$stmt->bind_param('i', $idC);
$stmt->execute();
$historico = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$valE = function (string $col) use ($editando) {
    return $editando && isset($editando[$col]) && $editando[$col] !== null ? (string)$editando[$col] : '';
};

$base   = '../../';
$active = 'medidas';
$titulo = $cliente['nombre_completo'];
include __DIR__ . '/../../includes/sidebar.php';
?>
<main class="page">

  <header class="ck-header">
    <p class="text-soft">Medidas de</p>
    <h2 class="h1"><?= e($cliente['nombre_completo']) ?></h2>
    <div class="form-actions">
      <a class="btn btn-outline btn-mini" href="dietista_medidas.php">← Otros clientes</a>
      <a class="btn btn-outline btn-mini" href="dietista_ficha.php?id=<?= (int)$idC ?>">Ficha</a>
    </div>
  </header>

  <?php if ($ok):    ?><div class="alert alert-success" role="status"><?= e($ok) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"  role="alert"><?= e($error) ?></div><?php endif; ?>

  <!-- FORM nueva/editar -->
  <form method="post" class="card">
    <h2 class="card-title"><?= $editando ? '✏️ Editar medida' : '➕ Nueva medida' ?></h2>
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="accion" value="guardar">
    <?php if ($editando): ?><input type="hidden" name="id" value="<?= (int)$editando['id'] ?>"><?php endif; ?>

    <label class="field">
      <span class="field-label">Fecha</span>
      <input class="field-input" type="date" name="fecha" required
             value="<?= e($editando['fecha'] ?? date('Y-m-d')) ?>">
    </label>

    <div class="medidas-grid">
      <?php foreach ($campos as $col => $label): ?>
        <label class="field">
          <span class="field-label"><?= e($label) ?> <span class="text-muted">(cm)</span></span>
          <input class="field-input" type="number" step="0.1" min="0" max="300"
                 name="<?= e($col) ?>" inputmode="decimal" value="<?= e($valE($col)) ?>">
        </label>
      <?php endforeach; ?>
    </div>

    <label class="field">
      <span class="field-label">Notas</span>
      <textarea class="field-textarea" name="notas" maxlength="500"><?= e($valE('notas')) ?></textarea>
    </label>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary btn-block"><?= $editando ? 'Actualizar' : 'Guardar' ?></button>
      <?php if ($editando): ?>
        <a class="btn btn-outline btn-block" href="?id_cliente=<?= (int)$idC ?>">Cancelar</a>
      <?php endif; ?>
    </div>
  </form>

  <!-- HISTÓRICO -->
  <section class="card">
    <h2 class="card-title">📋 Histórico (<?= count($historico) ?>)</h2>
    <?php if (!$historico): ?>
      <p class="text-muted">Sin medidas registradas.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Fecha</th>
              <?php foreach ($campos as $col => $label): ?>
                <th><?= e($label) ?></th>
              <?php endforeach; ?>
              <th class="td-actions"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($historico as $r): ?>
              <tr>
                <td><strong><?= e(date('d/m/Y', strtotime($r['fecha']))) ?></strong></td>
                <?php foreach ($campos as $col => $_):
                  $vv = $r[$col] !== null ? rtrim(rtrim((string)$r[$col], '0'), '.') : '—'; ?>
                  <td><?= e($vv) ?></td>
                <?php endforeach; ?>
                <td class="td-actions">
                  <a class="btn btn-ghost btn-mini" href="?id_cliente=<?= (int)$idC ?>&edit=<?= (int)$r['id'] ?>">✏️</a>
                  <form method="post" class="inline-form" onsubmit="return confirm('¿Borrar?');">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="accion" value="borrar">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
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