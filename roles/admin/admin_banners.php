<?php
require_once __DIR__ . '/../../includes/conexion.php';
requiere_rol('admin', '../../');

$uid   = usuario_id();
$ok    = '';
$error = '';

// ============================================================
// POST: CRUD
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $error = 'Sesión expirada.';
    } else {
        $accion = $_POST['accion'] ?? '';

        if ($accion === 'guardar') {
            $idEdit  = (int)($_POST['id'] ?? 0);
            $mensaje = trim($_POST['mensaje'] ?? '');
            $activo  = isset($_POST['activo']) ? 1 : 0;

            if ($mensaje === '') {
                $error = 'El mensaje no puede estar vacío.';
            } elseif (mb_strlen($mensaje) > 500) {
                $error = 'Máximo 500 caracteres.';
            } else {
                if ($idEdit > 0) {
                    $stmt = $conn->prepare("UPDATE banners_sistema SET mensaje=?, activo=? WHERE id=?");
                    $stmt->bind_param('sii', $mensaje, $activo, $idEdit);
                    if ($stmt->execute()) {
                        log_admin($conn, $uid, 'EDITAR_BANNER', "Editado banner #{$idEdit}");
                        $ok = 'Banner actualizado.';
                    } else $error = 'No se pudo actualizar.';
                    $stmt->close();
                } else {
                    $stmt = $conn->prepare("INSERT INTO banners_sistema (mensaje, activo) VALUES (?, ?)");
                    $stmt->bind_param('si', $mensaje, $activo);
                    if ($stmt->execute()) {
                        $newId = $stmt->insert_id;
                        log_admin($conn, $uid, 'CREAR_BANNER', "Creado banner #{$newId}");
                        $ok = 'Banner creado.';
                    } else $error = 'No se pudo crear.';
                    $stmt->close();
                }
            }
        }
        elseif ($accion === 'toggle') {
            $id = (int)$_POST['id'];
            $stmt = $conn->prepare("UPDATE banners_sistema SET activo = 1 - activo WHERE id=?");
            $stmt->bind_param('i', $id);
            if ($stmt->execute()) {
                log_admin($conn, $uid, 'TOGGLE_BANNER', "Toggle banner #{$id}");
                $ok = 'Estado cambiado.';
            }
            $stmt->close();
        }
        elseif ($accion === 'borrar') {
            $idDel = (int)$_POST['id'];
            $stmt = $conn->prepare("DELETE FROM banners_sistema WHERE id=?");
            $stmt->bind_param('i', $idDel);
            if ($stmt->execute()) {
                log_admin($conn, $uid, 'BORRAR_BANNER', "Borrado banner #{$idDel}");
                $ok = 'Banner borrado.';
            }
            $stmt->close();
        }
    }
}

// --- Edición ---
$editando = null;
if (isset($_GET['edit'])) {
    $idE = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM banners_sistema WHERE id=?");
    $stmt->bind_param('i', $idE);
    $stmt->execute();
    $editando = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// --- Listado ---
$banners = $conn->query("SELECT * FROM banners_sistema ORDER BY activo DESC, id DESC")
                ->fetch_all(MYSQLI_ASSOC);

$base   = '../../';
$active = 'banners';
$titulo = 'Banners del sistema';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main class="page">

  <?php if ($ok):    ?><div class="alert alert-success" role="status"><?= e($ok) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"  role="alert"><?= e($error) ?></div><?php endif; ?>

  <!-- Form crear/editar -->
  <form method="post" class="card">
    <h2 class="card-title"><?= $editando ? '✏️ Editar banner' : '➕ Nuevo banner' ?></h2>
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="accion" value="guardar">
    <?php if ($editando): ?><input type="hidden" name="id" value="<?= (int)$editando['id'] ?>"><?php endif; ?>

    <label class="field">
      <span class="field-label">Mensaje <span class="text-muted">(máx. 500 caracteres)</span></span>
      <textarea class="field-textarea" name="mensaje" maxlength="500" required rows="4"><?= e($editando['mensaje'] ?? '') ?></textarea>
      <span class="field-help">Se mostrará a todos los usuarios logueados, debajo de la barra superior.</span>
    </label>

    <label class="field">
      <span class="field-label">
        <input type="checkbox" name="activo" value="1" <?= !$editando || (int)$editando['activo'] === 1 ? 'checked' : '' ?>>
        Visible para los usuarios
      </span>
    </label>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary btn-block"><?= $editando ? 'Actualizar' : 'Crear banner' ?></button>
      <?php if ($editando): ?>
        <a class="btn btn-outline btn-block" href="admin_banners.php">Cancelar</a>
      <?php endif; ?>
    </div>
  </form>

  <!-- Listado -->
  <section class="card">
    <h3 class="card-title">📋 <?= count($banners) ?> banner<?= count($banners) === 1 ? '' : 's' ?></h3>

    <?php if (!$banners): ?>
      <p class="text-muted">No hay banners creados.</p>
    <?php else: ?>
      <ul class="banner-list" role="list">
        <?php foreach ($banners as $b): ?>
          <li class="banner-row<?= (int)$b['activo'] === 0 ? ' is-inactive' : '' ?>">
            <div class="banner-msg"><?= nl2br(e($b['mensaje'])) ?></div>
            <div class="banner-actions">
              <form method="post" class="inline-form">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="accion" value="toggle">
                <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-mini" title="<?= (int)$b['activo'] ? 'Desactivar' : 'Activar' ?>">
                  <?= (int)$b['activo'] ? '✅' : '⬜' ?>
                </button>
              </form>
              <a class="btn btn-ghost btn-mini" href="?edit=<?= (int)$b['id'] ?>">✏️</a>
              <form method="post" class="inline-form" onsubmit="return confirm('¿Borrar este banner?');">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="accion" value="borrar">
                <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-mini">🗑️</button>
              </form>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>