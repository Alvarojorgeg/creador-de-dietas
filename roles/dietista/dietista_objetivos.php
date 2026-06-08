<?php
require_once __DIR__ . '/../../includes/conexion.php';
requiere_rol('dietista', '../../');

$uid   = usuario_id();
$ok    = '';
$error = '';
$idC   = isset($_GET['id_cliente']) ? (int)$_GET['id_cliente'] : 0;

$tipos = [
    'peso'   => ['Peso',   'kg'],
    'grasa'  => ['% grasa','%'],
    'medida' => ['Medida', 'cm'],
    'custom' => ['Otro',   ''],
];
$estados = ['activo','completado','fallado','cancelado'];

// Verificar cliente
$cliente = null;
if ($idC > 0) {
    $stmt = $conn->prepare("SELECT id, nombre_completo FROM usuarios WHERE id=? AND id_dietista=? AND rol='cliente' AND activo=1");
    $stmt->bind_param('ii', $idC, $uid);
    $stmt->execute();
    $cliente = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$cliente) { header('Location: dietista_objetivos.php'); exit; }
}

// ============================================================
// MODO LISTADO
// ============================================================
if (!$cliente) {
    $stmt = $conn->prepare(
        "SELECT u.id, u.nombre_completo,
                (SELECT COUNT(*) FROM objetivos WHERE id_cliente=u.id AND estado='activo')     AS activos,
                (SELECT COUNT(*) FROM objetivos WHERE id_cliente=u.id AND estado='completado') AS completados
         FROM usuarios u
         WHERE u.rol='cliente' AND u.id_dietista=? AND u.activo=1
         ORDER BY u.nombre_completo"
    );
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $lista = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $base   = '../../';
    $active = 'objetivos';
    $titulo = 'Objetivos';
    include __DIR__ . '/../../includes/sidebar.php';
    ?>
    <main class="page">
      <section class="card">
        <h2 class="card-title">🎯 Selecciona un cliente</h2>
        <?php if (!$lista): ?>
          <p class="text-muted">Sin clientes asignados.</p>
        <?php else: ?>
          <ul class="dt-clients" role="list">
            <?php foreach ($lista as $c): ?>
              <li class="dt-client">
                <a class="dt-client-link" href="?id_cliente=<?= (int)$c['id'] ?>">
                  <div class="chats-avatar"><?= e(mb_strtoupper(mb_substr($c['nombre_completo'], 0, 1, 'UTF-8'), 'UTF-8')) ?></div>
                  <div class="dt-client-info">
                    <div class="dt-client-name"><?= e($c['nombre_completo']) ?></div>
                    <div class="dt-client-meta">
                      <span class="dt-pill dt-pill--ok"><?= (int)$c['activos'] ?> activos</span>
                      <span class="text-muted"><?= (int)$c['completados'] ?> completados</span>
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
// POST sobre objetivos de este cliente
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $error = 'Sesión expirada.';
    } else {
        $accion = $_POST['accion'] ?? '';

        if ($accion === 'guardar') {
            $idEdit  = (int)($_POST['id'] ?? 0);
            $titulo2 = trim($_POST['titulo'] ?? '');
            $desc    = trim($_POST['descripcion'] ?? '');
            $tipo    = $_POST['tipo'] ?? 'peso';
            $vi      = ($_POST['valor_inicial']  ?? '') === '' ? null : (float)$_POST['valor_inicial'];
            $vo      = ($_POST['valor_objetivo'] ?? '') === '' ? null : (float)$_POST['valor_objetivo'];
            $unidad  = trim($_POST['unidad'] ?? '');
            $finicio = $_POST['fecha_inicio'] ?? date('Y-m-d');
            $flimite = $_POST['fecha_limite'] ?? null;
            if ($flimite === '') $flimite = null;
            if ($unidad === '' && isset($tipos[$tipo])) $unidad = $tipos[$tipo][1];

            if ($titulo2 === '')                    $error = 'Título obligatorio.';
            elseif (!isset($tipos[$tipo]))          $error = 'Tipo inválido.';
            else {
                if ($idEdit > 0) {
                    $stmt = $conn->prepare(
                        "UPDATE objetivos SET titulo=?, descripcion=?, tipo=?, valor_inicial=?, valor_objetivo=?,
                            unidad=?, fecha_inicio=?, fecha_limite=?
                         WHERE id=? AND id_cliente=?"
                    );
                    $stmt->bind_param('sssddsssii',
                        $titulo2, $desc, $tipo, $vi, $vo, $unidad, $finicio, $flimite, $idEdit, $idC);
                    $ok = $stmt->execute() ? 'Objetivo actualizado.' : 'No se pudo actualizar.';
                    $stmt->close();
                } else {
                    $stmt = $conn->prepare(
                        "INSERT INTO objetivos
                           (id_cliente, id_dietista, titulo, descripcion, tipo, valor_inicial, valor_objetivo,
                            unidad, fecha_inicio, fecha_limite, estado)
                         VALUES (?,?,?,?,?,?,?,?,?,?, 'activo')"
                    );
                    $stmt->bind_param('iisssddsss',
                        $idC, $uid, $titulo2, $desc, $tipo, $vi, $vo, $unidad, $finicio, $flimite);
                    $ok = $stmt->execute() ? 'Objetivo creado.' : 'No se pudo crear.';
                    $stmt->close();
                }
            }
        }
        elseif ($accion === 'estado') {
            $id    = (int)$_POST['id'];
            $nuevo = $_POST['estado'] ?? '';
            if (in_array($nuevo, $estados, true)) {
                $compFecha = ($nuevo === 'completado') ? date('Y-m-d') : null;
                $stmt = $conn->prepare("UPDATE objetivos SET estado=?, fecha_completado=? WHERE id=? AND id_cliente=?");
                $stmt->bind_param('ssii', $nuevo, $compFecha, $id, $idC);
                $ok = $stmt->execute() ? 'Estado actualizado.' : 'No se pudo cambiar.';
                $stmt->close();
            }
        }
        elseif ($accion === 'borrar') {
            $id = (int)$_POST['id'];
            $stmt = $conn->prepare("DELETE FROM objetivos WHERE id=? AND id_cliente=?");
            $stmt->bind_param('ii', $id, $idC);
            $ok = $stmt->execute() && $stmt->affected_rows > 0 ? 'Objetivo borrado.' : 'No se pudo borrar.';
            $stmt->close();
        }
    }
}

// Edición
$editando = null;
if (isset($_GET['edit'])) {
    $idE = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM objetivos WHERE id=? AND id_cliente=?");
    $stmt->bind_param('ii', $idE, $idC);
    $stmt->execute();
    $editando = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Listado
$stmt = $conn->prepare(
    "SELECT * FROM objetivos WHERE id_cliente=?
     ORDER BY (estado='activo') DESC, fecha_creacion DESC"
);
$stmt->bind_param('i', $idC);
$stmt->execute();
$objetivos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Último peso para % progreso
$stmt = $conn->prepare("SELECT peso_kg FROM progresos_metricas WHERE id_cliente=? ORDER BY fecha_hora DESC LIMIT 1");
$stmt->bind_param('i', $idC);
$stmt->execute();
$ultimoPeso = (float)($stmt->get_result()->fetch_assoc()['peso_kg'] ?? 0);
$stmt->close();

function progreso_pct_dt(array $o, float $actual): ?int {
    if ($o['tipo'] !== 'peso' || $o['valor_inicial'] === null || $o['valor_objetivo'] === null || $actual <= 0) return null;
    $ini = (float)$o['valor_inicial'];
    $obj = (float)$o['valor_objetivo'];
    if (abs($obj - $ini) < 0.001) return 100;
    $pct = (($ini - $actual) / ($ini - $obj)) * 100;
    return max(0, min(100, (int)round($pct)));
}

function val_d(array $arr, string $k, $def = ''): string {
    return isset($arr[$k]) && $arr[$k] !== null ? (string)$arr[$k] : (string)$def;
}

$base   = '../../';
$active = 'objetivos';
$titulo = $cliente['nombre_completo'];
include __DIR__ . '/../../includes/sidebar.php';
?>
<main class="page">

  <header class="ck-header">
    <p class="text-soft">Objetivos de</p>
    <h2 class="h1"><?= e($cliente['nombre_completo']) ?></h2>
    <div class="form-actions">
      <a class="btn btn-outline btn-mini" href="dietista_objetivos.php">← Otros clientes</a>
      <a class="btn btn-outline btn-mini" href="dietista_ficha.php?id=<?= $idC ?>">Ficha</a>
    </div>
  </header>

  <?php if ($ok):    ?><div class="alert alert-success" role="status"><?= e($ok) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"  role="alert"><?= e($error) ?></div><?php endif; ?>

  <!-- Form crear/editar -->
  <form method="post" class="card">
    <h2 class="card-title"><?= $editando ? '✏️ Editar objetivo' : '➕ Nuevo objetivo' ?></h2>
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="accion" value="guardar">
    <?php if ($editando): ?><input type="hidden" name="id" value="<?= (int)$editando['id'] ?>"><?php endif; ?>

    <label class="field">
      <span class="field-label">Título</span>
      <input class="field-input" type="text" name="titulo" required maxlength="160"
             value="<?= e(val_d($editando ?: [], 'titulo')) ?>">
    </label>

    <div class="grid-2">
      <label class="field">
        <span class="field-label">Tipo</span>
        <select class="field-select" name="tipo">
          <?php foreach ($tipos as $k => $info): ?>
            <option value="<?= e($k) ?>" <?= val_d($editando ?: [], 'tipo','peso') === $k ? 'selected' : '' ?>>
              <?= e($info[0]) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field">
        <span class="field-label">Unidad</span>
        <input class="field-input" type="text" name="unidad" maxlength="20"
               value="<?= e(val_d($editando ?: [], 'unidad')) ?>">
      </label>
    </div>

    <div class="grid-2">
      <label class="field">
        <span class="field-label">Valor inicial</span>
        <input class="field-input" type="number" step="0.1" name="valor_inicial" inputmode="decimal"
               value="<?= e(val_d($editando ?: [], 'valor_inicial')) ?>">
      </label>
      <label class="field">
        <span class="field-label">Valor objetivo</span>
        <input class="field-input" type="number" step="0.1" name="valor_objetivo" inputmode="decimal"
               value="<?= e(val_d($editando ?: [], 'valor_objetivo')) ?>">
      </label>
    </div>

    <div class="grid-2">
      <label class="field">
        <span class="field-label">Fecha inicio</span>
        <input class="field-input" type="date" name="fecha_inicio" required
               value="<?= e(val_d($editando ?: [], 'fecha_inicio', date('Y-m-d'))) ?>">
      </label>
      <label class="field">
        <span class="field-label">Fecha límite</span>
        <input class="field-input" type="date" name="fecha_limite"
               value="<?= e(val_d($editando ?: [], 'fecha_limite')) ?>">
      </label>
    </div>

    <label class="field">
      <span class="field-label">Descripción</span>
      <textarea class="field-textarea" name="descripcion" maxlength="500"><?= e(val_d($editando ?: [], 'descripcion')) ?></textarea>
    </label>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary btn-block"><?= $editando ? 'Actualizar' : 'Crear' ?></button>
      <?php if ($editando): ?>
        <a class="btn btn-outline btn-block" href="?id_cliente=<?= $idC ?>">Cancelar</a>
      <?php endif; ?>
    </div>
  </form>

  <!-- Listado -->
  <?php if (!$objetivos): ?>
    <div class="card text-center">
      <p class="text-muted">Sin objetivos para este cliente todavía.</p>
    </div>
  <?php else: foreach ($objetivos as $o):
    $pct = progreso_pct_dt($o, $ultimoPeso);
  ?>
    <article class="card obj-card obj-card--<?= e($o['estado']) ?>">
      <header class="obj-head">
        <div class="obj-titles">
          <h3 class="obj-title"><?= e($o['titulo']) ?></h3>
          <span class="obj-tipo"><?= e($tipos[$o['tipo']][0] ?? $o['tipo']) ?></span>
        </div>
        <span class="obj-badge obj-badge--<?= e($o['estado']) ?>"><?= e(ucfirst($o['estado'])) ?></span>
      </header>

      <?php if (!empty($o['descripcion'])): ?>
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

      <?php if ($pct !== null && $o['estado'] === 'activo'): ?>
        <div class="obj-progress" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100">
          <div class="obj-progress-bar" data-pct="<?= $pct ?>"></div>
        </div>
        <p class="obj-progress-lbl"><?= $pct ?>% completado</p>
      <?php endif; ?>

      <footer class="obj-actions">
        <?php if ($o['estado'] === 'activo'): ?>
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
            <input type="hidden" name="estado" value="fallado">
            <button type="submit" class="btn btn-outline btn-mini">Fallado</button>
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

        <a class="btn btn-ghost btn-mini" href="?id_cliente=<?= $idC ?>&edit=<?= (int)$o['id'] ?>">✏️ Editar</a>

        <form method="post" class="inline-form" onsubmit="return confirm('¿Borrar?');">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="accion" value="borrar">
          <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
          <button type="submit" class="btn btn-ghost btn-mini">🗑️</button>
        </form>
      </footer>
    </article>
  <?php endforeach; endif; ?>

</main>

<script src="<?= e($base) ?>js/objetivos.js" defer></script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>