<?php
require_once __DIR__ . '/../../includes/conexion.php';
require_once __DIR__ . '/../../includes/predicciones.php';
requiere_rol('cliente', '../../');

$uid   = usuario_id();
$ok    = '';
$error = '';

$tipos = [
    'peso'   => ['Peso',   'kg'],
    'grasa'  => ['% grasa','%'],
    'medida' => ['Medida', 'cm'],
    'custom' => ['Otro',   ''],
];
$estados = ['activo','completado','fallado','cancelado'];

// --- POST: guardar / cambiar estado / borrar ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $error = 'Sesión expirada. Recarga la página.';
    } else {
        $accion = $_POST['accion'] ?? '';

        if ($accion === 'guardar') {
            $idEdit  = (int)($_POST['id'] ?? 0);
            $titulo  = trim($_POST['titulo'] ?? '');
            $desc    = trim($_POST['descripcion'] ?? '');
            $tipo    = $_POST['tipo'] ?? 'peso';
            $vi      = ($_POST['valor_inicial']  ?? '') === '' ? null : (float)$_POST['valor_inicial'];
            $vo      = ($_POST['valor_objetivo'] ?? '') === '' ? null : (float)$_POST['valor_objetivo'];
            $unidad  = trim($_POST['unidad'] ?? '');
            $finicio = $_POST['fecha_inicio'] ?? date('Y-m-d');
            $flimite = $_POST['fecha_limite'] ?? null;
            if ($flimite === '') $flimite = null;

            if ($titulo === '') {
                $error = 'El título es obligatorio.';
            } elseif (!isset($tipos[$tipo])) {
                $error = 'Tipo no válido.';
            } else {
                if ($unidad === '') $unidad = $tipos[$tipo][1];

                if ($idEdit > 0) {
                    $stmt = $conn->prepare(
                        "UPDATE objetivos SET titulo=?, descripcion=?, tipo=?, valor_inicial=?, valor_objetivo=?,
                            unidad=?, fecha_inicio=?, fecha_limite=?
                         WHERE id=? AND id_cliente=?"
                    );
                    $stmt->bind_param('sssddsssii',
                        $titulo, $desc, $tipo, $vi, $vo, $unidad, $finicio, $flimite, $idEdit, $uid);
                    $ok = $stmt->execute() ? 'Objetivo actualizado.' : 'No se pudo actualizar.';
                    $stmt->close();
                } else {
                    $stmt = $conn->prepare(
                        "INSERT INTO objetivos
                           (id_cliente, titulo, descripcion, tipo, valor_inicial, valor_objetivo,
                            unidad, fecha_inicio, fecha_limite, estado)
                         VALUES (?,?,?,?,?,?,?,?,?, 'activo')"
                    );
                    $stmt->bind_param('isssddsss',
                        $uid, $titulo, $desc, $tipo, $vi, $vo, $unidad, $finicio, $flimite);
                    $ok = $stmt->execute() ? 'Objetivo creado.' : 'No se pudo crear.';
                    $stmt->close();
                }
            }
        }
        elseif ($accion === 'estado') {
            $idE   = (int)($_POST['id'] ?? 0);
            $nuevo = $_POST['estado'] ?? '';
            if (in_array($nuevo, $estados, true)) {
                $compFecha = ($nuevo === 'completado') ? date('Y-m-d') : null;
                $stmt = $conn->prepare(
                    "UPDATE objetivos SET estado=?, fecha_completado=?
                     WHERE id=? AND id_cliente=?"
                );
                $stmt->bind_param('ssii', $nuevo, $compFecha, $idE, $uid);
                $ok = $stmt->execute() ? 'Estado actualizado.' : 'No se pudo cambiar.';
                $stmt->close();
            }
        }
        elseif ($accion === 'borrar') {
            $idDel = (int)($_POST['id'] ?? 0);
            $stmt = $conn->prepare("DELETE FROM objetivos WHERE id=? AND id_cliente=?");
            $stmt->bind_param('ii', $idDel, $uid);
            $ok = $stmt->execute() ? 'Objetivo borrado.' : 'No se pudo borrar.';
            $stmt->close();
        }
    }
}

// --- Edición ---
$editando = null;
if (isset($_GET['edit'])) {
    $idE = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM objetivos WHERE id=? AND id_cliente=?");
    $stmt->bind_param('ii', $idE, $uid);
    $stmt->execute();
    $editando = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// --- Listado ---
$stmt = $conn->prepare(
    "SELECT * FROM objetivos WHERE id_cliente=?
     ORDER BY (estado='activo') DESC, fecha_creacion DESC"
);
$stmt->bind_param('i', $uid);
$stmt->execute();
$objetivos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// --- Último peso (para progreso y proyección) ---
$stmt = $conn->prepare(
    "SELECT peso_kg FROM progresos_metricas WHERE id_cliente=? ORDER BY fecha_hora DESC LIMIT 1"
);
$stmt->bind_param('i', $uid);
$stmt->execute();
$ultimoPeso = (float)($stmt->get_result()->fetch_assoc()['peso_kg'] ?? 0);
$stmt->close();

// --- Predicciones (para calcular fecha estimada de objetivos de peso) ---
$pred = predecir_cliente($conn, $uid);
$kgPorDia = ($pred['ok'] && $pred['kg_dia'] !== null) ? (float)$pred['kg_dia'] : 0.0;

function progreso_pct(array $o, float $actual): ?int {
    if ($o['tipo'] !== 'peso' || $o['valor_inicial'] === null || $o['valor_objetivo'] === null || $actual <= 0) return null;
    $ini = (float)$o['valor_inicial'];
    $obj = (float)$o['valor_objetivo'];
    if (abs($obj - $ini) < 0.001) return 100;
    $pct = (($ini - $actual) / ($ini - $obj)) * 100;
    return max(0, min(100, (int)round($pct)));
}

function val_def(array $arr, string $k, $d = ''): string {
    return isset($arr[$k]) && $arr[$k] !== null ? (string)$arr[$k] : (string)$d;
}

// Formato fecha es-ES
function fecha_es(string $ymd): string {
    try {
        $d = new DateTime($ymd);
        $meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
        return (int)$d->format('j') . ' de ' . $meses[(int)$d->format('n') - 1] . ' de ' . $d->format('Y');
    } catch (Throwable $e) { return $ymd; }
}

$base   = '../../';
$active = 'objetivos';
$titulo = 'Objetivos';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main class="page">

  <?php if ($ok):    ?><div class="alert alert-success" role="status"><?= e($ok) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"  role="alert"><?= e($error) ?></div><?php endif; ?>

  <!-- FORM crear / editar -->
  <form method="post" class="card">
    <h2 class="card-title"><?= $editando ? '✏️ Editar objetivo' : '➕ Nuevo objetivo' ?></h2>
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="accion" value="guardar">
    <?php if ($editando): ?>
      <input type="hidden" name="id" value="<?= (int)$editando['id'] ?>">
    <?php endif; ?>

    <label class="field">
      <span class="field-label">Título</span>
      <input class="field-input" type="text" name="titulo" required maxlength="160"
             value="<?= e(val_def($editando ?: [], 'titulo')) ?>"
             placeholder="Ej: Bajar a 75 kg">
    </label>

    <div class="grid-2">
      <label class="field">
        <span class="field-label">Tipo</span>
        <select class="field-select" name="tipo">
          <?php foreach ($tipos as $k => $info): ?>
            <option value="<?= e($k) ?>" <?= val_def($editando ?: [], 'tipo','peso') === $k ? 'selected' : '' ?>>
              <?= e($info[0]) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field">
        <span class="field-label">Unidad</span>
        <input class="field-input" type="text" name="unidad" maxlength="20"
               value="<?= e(val_def($editando ?: [], 'unidad')) ?>"
               placeholder="kg, cm, %...">
      </label>
    </div>

    <div class="grid-2">
      <label class="field">
        <span class="field-label">Valor inicial</span>
        <input class="field-input" type="number" step="0.1" name="valor_inicial" inputmode="decimal"
               value="<?= e(val_def($editando ?: [], 'valor_inicial')) ?>">
      </label>
      <label class="field">
        <span class="field-label">Valor objetivo</span>
        <input class="field-input" type="number" step="0.1" name="valor_objetivo" inputmode="decimal"
               value="<?= e(val_def($editando ?: [], 'valor_objetivo')) ?>">
      </label>
    </div>

    <div class="grid-2">
      <label class="field">
        <span class="field-label">Fecha de inicio</span>
        <input class="field-input" type="date" name="fecha_inicio" required
               value="<?= e(val_def($editando ?: [], 'fecha_inicio', date('Y-m-d'))) ?>">
      </label>
      <label class="field">
        <span class="field-label">Fecha límite (opcional)</span>
        <input class="field-input" type="date" name="fecha_limite"
               value="<?= e(val_def($editando ?: [], 'fecha_limite')) ?>">
      </label>
    </div>

    <label class="field">
      <span class="field-label">Descripción</span>
      <textarea class="field-textarea" name="descripcion" maxlength="500"
                placeholder="¿Por qué te marcas este objetivo?"><?= e(val_def($editando ?: [], 'descripcion')) ?></textarea>
    </label>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary btn-block">
        <?= $editando ? 'Actualizar' : 'Crear objetivo' ?>
      </button>
      <?php if ($editando): ?>
        <a class="btn btn-outline btn-block" href="cliente_objetivos.php">Cancelar</a>
      <?php endif; ?>
    </div>
  </form>

  <!-- LISTADO -->
  <?php if (!$objetivos): ?>
    <div class="card text-center">
      <p class="text-muted">Aún no tienes objetivos. ¡Crea el primero!</p>
    </div>
  <?php else: ?>
    <?php foreach ($objetivos as $o):
      $pct = progreso_pct($o, $ultimoPeso);
      $estado = $o['estado'];
      // Estimación SOLO para objetivos activos de peso con datos suficientes
      $estimacion = null;
      if ($estado === 'activo' && $o['tipo'] === 'peso' && $ultimoPeso > 0 && $pred['ok']) {
          $estimacion = fecha_estimada_objetivo($o, $ultimoPeso, $kgPorDia);
      }
    ?>
      <article class="card obj-card obj-card--<?= e($estado) ?>">
        <header class="obj-head">
          <div class="obj-titles">
            <h3 class="obj-title"><?= e($o['titulo']) ?></h3>
            <span class="obj-tipo"><?= e($tipos[$o['tipo']][0] ?? $o['tipo']) ?></span>
          </div>
          <span class="obj-badge obj-badge--<?= e($estado) ?>"><?= e(ucfirst($estado)) ?></span>
        </header>

        <?php if ($o['descripcion']): ?>
          <p class="obj-desc"><?= e($o['descripcion']) ?></p>
        <?php endif; ?>

        <?php if ($o['valor_inicial'] !== null || $o['valor_objetivo'] !== null): ?>
          <ul class="obj-stats" role="list">
            <?php if ($o['valor_inicial'] !== null): ?>
              <li><span>Inicial</span><strong><?= e(rtrim(rtrim($o['valor_inicial'], '0'), '.')) ?> <?= e((string)$o['unidad']) ?></strong></li>
            <?php endif; ?>
            <?php if ($o['valor_objetivo'] !== null): ?>
              <li><span>Objetivo</span><strong><?= e(rtrim(rtrim($o['valor_objetivo'], '0'), '.')) ?> <?= e((string)$o['unidad']) ?></strong></li>
            <?php endif; ?>
            <?php if ($o['fecha_limite']): ?>
              <li><span>Límite</span><strong><?= e(date('d/m/Y', strtotime($o['fecha_limite']))) ?></strong></li>
            <?php endif; ?>
          </ul>
        <?php endif; ?>

        <?php if ($pct !== null && $estado === 'activo'): ?>
          <div class="obj-progress" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100">
            <div class="obj-progress-bar" data-pct="<?= $pct ?>"></div>
          </div>
          <p class="obj-progress-lbl"><?= $pct ?>% completado</p>
        <?php endif; ?>

        <?php if ($estimacion !== null):
          $estClass = $estimacion['rumbo_ok'] ? 'is-ok' : 'is-warn';
        ?>
          <div class="obj-eta obj-eta--<?= e($estClass) ?>">
            <span class="obj-eta-icon"><?= $estimacion['rumbo_ok'] ? '🎯' : '⚠️' ?></span>
            <div class="obj-eta-body">
              <?php if ($estimacion['fecha']): ?>
                <strong class="obj-eta-fecha"><?= e(fecha_es($estimacion['fecha'])) ?></strong>
                <span class="obj-eta-sub">
                  <?php if ($estimacion['dias'] === 0): ?>
                    <?= e($estimacion['mensaje']) ?>
                  <?php else: ?>
                    Estimación a tu ritmo actual · <?= (int)$estimacion['dias'] ?> día<?= $estimacion['dias'] === 1 ? '' : 's' ?>
                    <?php
                      // Comparar con fecha límite si la hay
                      if ($o['fecha_limite']) {
                          $limite = new DateTime($o['fecha_limite']);
                          $estFecha = new DateTime($estimacion['fecha']);
                          $diff = (int)$limite->diff($estFecha)->days * ($estFecha > $limite ? 1 : -1);
                          if ($estFecha > $limite) echo ' · <span class="text-danger">' . $diff . ' días después del límite</span>';
                          elseif ($estFecha < $limite) echo ' · <span class="text-success">' . abs($diff) . ' días antes del límite</span>';
                      }
                    ?>
                  <?php endif; ?>
                </span>
              <?php else: ?>
                <strong class="obj-eta-fecha"><?= e($estimacion['mensaje']) ?></strong>
                <span class="obj-eta-sub">Habla con tu dietista para ajustar el plan.</span>
              <?php endif; ?>
            </div>
          </div>
        <?php elseif ($estado === 'activo' && $o['tipo'] === 'peso' && !$pred['ok']): ?>
          <p class="obj-eta-hint text-muted">
            💡 Para ver la fecha estimada necesitas: peso actual, cuestionario y dieta asignada.
          </p>
        <?php endif; ?>

        <footer class="obj-actions">
          <?php if ($estado === 'activo'): ?>
            <form method="post" class="inline-form">
              <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="accion" value="estado">
              <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
              <input type="hidden" name="estado" value="completado">
              <button type="submit" class="btn btn-primary btn-mini">✓ Completar</button>
            </form>
            <form method="post" class="inline-form">
              <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="accion" value="estado">
              <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
              <input type="hidden" name="estado" value="cancelado">
              <button type="submit" class="btn btn-outline btn-mini">Cancelar</button>
            </form>
          <?php else: ?>
            <form method="post" class="inline-form">
              <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="accion" value="estado">
              <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
              <input type="hidden" name="estado" value="activo">
              <button type="submit" class="btn btn-outline btn-mini">Reactivar</button>
            </form>
          <?php endif; ?>

          <a class="btn btn-ghost btn-mini" href="?edit=<?= (int)$o['id'] ?>">✏️ Editar</a>

          <form method="post" class="inline-form" onsubmit="return confirm('¿Borrar este objetivo?');">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="accion" value="borrar">
            <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
            <button type="submit" class="btn btn-ghost btn-mini" aria-label="Borrar">🗑️</button>
          </form>
        </footer>
      </article>
    <?php endforeach; ?>
  <?php endif; ?>

</main>

<script src="<?= e($base) ?>js/objetivos.js" defer></script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
