<?php
require_once __DIR__ . '/../../includes/conexion.php';
requiere_rol('dietista', '../../');

$uid   = usuario_id();
$ok    = '';
$error = '';

$idEdit = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$idClientePre = isset($_GET['id_cliente']) ? (int)$_GET['id_cliente'] : 0;

$tipos = ['inicial','seguimiento','revision','rescate'];

// ============================================================
// POST: guardar
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $error = 'Sesión expirada.';
    } else {
        $accion = $_POST['accion'] ?? '';

        if ($accion === 'guardar') {
            $idEdit    = (int)($_POST['id'] ?? 0);
            $idCliente = (int)$_POST['id_cliente'];
            $fecha     = $_POST['fecha'] ?? '';
            $duracion  = (int)($_POST['duracion_min'] ?? 30);
            $tipo      = $_POST['tipo'] ?? 'seguimiento';
            $asistio   = isset($_POST['asistio']) ? 1 : 0;
            $notasPriv = trim($_POST['notas_privadas'] ?? '');
            $notasComp = trim($_POST['notas_compartidas'] ?? '');
            $plan      = trim($_POST['plan_siguiente'] ?? '');
            $proxCita  = $_POST['proxima_cita'] ?? null;
            if ($proxCita === '') $proxCita = null;

            // Convertir datetime-local
            $fecha = str_replace('T', ' ', $fecha);
            if (strlen($fecha) === 16) $fecha .= ':00';

            // Validar cliente
            $stmt = $conn->prepare("SELECT id FROM usuarios WHERE id=? AND id_dietista=? AND rol='cliente'");
            $stmt->bind_param('ii', $idCliente, $uid);
            $stmt->execute();
            $clienteOk = (bool)$stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$clienteOk)                      $error = 'Cliente no válido.';
            elseif (!in_array($tipo, $tipos, true)) $error = 'Tipo inválido.';
            elseif ($fecha === '' || strtotime($fecha) === false) $error = 'Fecha inválida.';
            elseif ($duracion < 5 || $duracion > 600)             $error = 'Duración inválida.';
            else {
                if ($idEdit > 0) {
                    $stmt = $conn->prepare(
                        "UPDATE consultas SET id_cliente=?, fecha=?, duracion_min=?, tipo=?, asistio=?,
                            notas_privadas=?, notas_compartidas=?, plan_siguiente=?, proxima_cita=?
                         WHERE id=? AND id_dietista=?"
                    );
                    $stmt->bind_param('isisissssii',
                        $idCliente, $fecha, $duracion, $tipo, $asistio,
                        $notasPriv, $notasComp, $plan, $proxCita, $idEdit, $uid);
                    if ($stmt->execute()) {
                        $stmt->close();
                        header('Location: dietista_consultas.php?guardado=1');
                        exit;
                    }
                    $stmt->close();
                    $error = 'No se pudo actualizar.';
                } else {
                    $stmt = $conn->prepare(
                        "INSERT INTO consultas
                           (id_cliente, id_dietista, fecha, duracion_min, tipo, asistio,
                            notas_privadas, notas_compartidas, plan_siguiente, proxima_cita)
                         VALUES (?,?,?,?,?,?,?,?,?,?)"
                    );
                    $stmt->bind_param('iisisissss',
                        $idCliente, $uid, $fecha, $duracion, $tipo, $asistio,
                        $notasPriv, $notasComp, $plan, $proxCita);
                    if ($stmt->execute()) {
                        $stmt->close();
                        header('Location: dietista_consultas.php?guardado=1');
                        exit;
                    }
                    $stmt->close();
                    $error = 'No se pudo crear.';
                }
            }
        }
        elseif ($accion === 'borrar') {
            $idDel = (int)$_POST['id'];
            $stmt = $conn->prepare("DELETE FROM consultas WHERE id=? AND id_dietista=?");
            $stmt->bind_param('ii', $idDel, $uid);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $stmt->close();
                header('Location: dietista_consultas.php?borrada=1');
                exit;
            }
            $stmt->close();
            $error = 'No se pudo borrar.';
        }
    }
}

// ============================================================
// Cargar consulta para edición
// ============================================================
$c = null;
if ($idEdit > 0) {
    $stmt = $conn->prepare("SELECT * FROM consultas WHERE id=? AND id_dietista=?");
    $stmt->bind_param('ii', $idEdit, $uid);
    $stmt->execute();
    $c = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$c) { header('Location: dietista_consultas.php'); exit; }
}

// Clientes
$stmt = $conn->prepare("SELECT id, nombre_completo FROM usuarios WHERE rol='cliente' AND id_dietista=? AND activo=1 ORDER BY nombre_completo");
$stmt->bind_param('i', $uid);
$stmt->execute();
$clientes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$idClienteSel = $c['id_cliente'] ?? $idClientePre;
$fechaSel  = $c ? date('Y-m-d\TH:i', strtotime($c['fecha']))   : date('Y-m-d\TH:i', strtotime('+1 day 10:00'));
$dur       = (int)($c['duracion_min'] ?? 30);
$tipoSel   = $c['tipo'] ?? 'seguimiento';
$asistio   = isset($c['asistio']) ? (int)$c['asistio'] : 1;
$notasPriv = $c['notas_privadas']     ?? '';
$notasComp = $c['notas_compartidas']  ?? '';
$plan      = $c['plan_siguiente']     ?? '';
$proxCita  = $c['proxima_cita']       ?? '';

$base   = '../../';
$active = 'consultas';
$titulo = $c ? 'Editar consulta' : 'Nueva consulta';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main class="page">

  <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>

  <form method="post" class="card">
    <header class="dash-card-header">
      <h2 class="card-title"><?= $c ? '✏️ Editar consulta' : '➕ Nueva consulta' ?></h2>
      <a class="dash-card-link" href="dietista_consultas.php">← Volver</a>
    </header>

    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="accion" value="guardar">
    <?php if ($c): ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><?php endif; ?>

    <label class="field">
      <span class="field-label">Cliente</span>
      <select class="field-select" name="id_cliente" required>
        <option value="">— Elegir —</option>
        <?php foreach ($clientes as $cl): ?>
          <option value="<?= (int)$cl['id'] ?>" <?= (int)$cl['id'] === (int)$idClienteSel ? 'selected' : '' ?>>
            <?= e($cl['nombre_completo']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <div class="grid-2">
      <label class="field">
        <span class="field-label">Fecha y hora</span>
        <input class="field-input" type="datetime-local" name="fecha" required value="<?= e($fechaSel) ?>">
      </label>
      <label class="field">
        <span class="field-label">Duración (min)</span>
        <input class="field-input" type="number" min="5" max="600" step="5" name="duracion_min" required value="<?= (int)$dur ?>">
      </label>
    </div>

    <div class="grid-2">
      <label class="field">
        <span class="field-label">Tipo</span>
        <select class="field-select" name="tipo">
          <?php foreach ($tipos as $t): ?>
            <option value="<?= e($t) ?>" <?= $tipoSel === $t ? 'selected' : '' ?>><?= e(ucfirst($t)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field">
        <span class="field-label">Próxima cita sugerida</span>
        <input class="field-input" type="date" name="proxima_cita" value="<?= e($proxCita) ?>">
      </label>
    </div>

    <label class="field">
      <span class="field-label">
        <input type="checkbox" name="asistio" value="1" <?= $asistio ? 'checked' : '' ?>>
        El cliente asistió a la consulta
      </span>
    </label>

    <label class="field">
      <span class="field-label">📝 Notas privadas <span class="text-muted">(no las ve el cliente)</span></span>
      <textarea class="field-textarea" name="notas_privadas" rows="4"><?= e($notasPriv) ?></textarea>
    </label>

    <label class="field">
      <span class="field-label">💬 Notas compartidas <span class="text-muted">(visibles para el cliente)</span></span>
      <textarea class="field-textarea" name="notas_compartidas" rows="4"><?= e($notasComp) ?></textarea>
    </label>

    <label class="field">
      <span class="field-label">📌 Plan para la próxima consulta <span class="text-muted">(visible para el cliente)</span></span>
      <textarea class="field-textarea" name="plan_siguiente" rows="4"><?= e($plan) ?></textarea>
    </label>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary btn-block"><?= $c ? 'Actualizar' : 'Crear consulta' ?></button>
      <a class="btn btn-outline btn-block" href="dietista_consultas.php">Cancelar</a>
    </div>
  </form>

  <?php if ($c): ?>
    <form method="post" class="card" onsubmit="return confirm('¿Borrar esta consulta?');">
      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="accion" value="borrar">
      <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
      <button type="submit" class="btn btn-danger btn-block">🗑️ Borrar consulta</button>
    </form>
  <?php endif; ?>

</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>