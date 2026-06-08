<?php
require_once __DIR__ . '/../../includes/conexion.php';
requiere_rol('dietista', '../../');

$uid   = usuario_id();
$ok    = '';
$error = '';

// ============================================================
// POST: crear plantilla / clonar / aplicar a cliente / borrar
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $error = 'Sesión expirada.';
    } else {
        $accion = $_POST['accion'] ?? '';

        if ($accion === 'nueva_plantilla') {
            $nombre = trim($_POST['nombre'] ?? '');
            $icono  = trim($_POST['icono'] ?? '📑');
            $color  = trim($_POST['color'] ?? '#2F9E73');
            $kcal   = (int)$_POST['kcal_objetivo'];
            $prot   = (int)$_POST['prot_objetivo'];
            $carb   = (int)$_POST['carb_objetivo'];
            $gras   = (int)$_POST['grasas_objetivo'];

            if ($nombre === '') {
                $error = 'Nombre obligatorio.';
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO dietas_base
                       (id_cliente, id_dietista, nombre, icono, color,
                        kcal_objetivo, prot_objetivo, carb_objetivo, grasas_objetivo)
                     VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param('isssiiii', $uid, $nombre, $icono, $color, $kcal, $prot, $carb, $gras);
                if ($stmt->execute()) {
                    $newId = $stmt->insert_id;
                    $stmt->close();
                    header('Location: dietista_dietas.php?id=' . $newId);
                    exit;
                }
                $stmt->close();
                $error = 'No se pudo crear la plantilla.';
            }
        }
        elseif ($accion === 'aplicar') {
            $idPlantilla = (int)$_POST['id_plantilla'];
            $idCliente   = (int)$_POST['id_cliente'];
            $nuevoNombre = trim($_POST['nuevo_nombre'] ?? '');

            // Verificar pertenencia
            $stmt = $conn->prepare("SELECT * FROM dietas_base WHERE id=? AND id_dietista=? AND id_cliente IS NULL");
            $stmt->bind_param('ii', $idPlantilla, $uid);
            $stmt->execute();
            $plantilla = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $stmt = $conn->prepare("SELECT id FROM usuarios WHERE id=? AND id_dietista=? AND rol='cliente'");
            $stmt->bind_param('ii', $idCliente, $uid);
            $stmt->execute();
            $clienteOk = (bool)$stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$plantilla || !$clienteOk) {
                $error = 'Plantilla o cliente no válidos.';
            } else {
                $conn->begin_transaction();
                try {
                    $nombreFinal = $nuevoNombre !== '' ? $nuevoNombre : $plantilla['nombre'];

                    // Clonar dieta_base
                    $stmt = $conn->prepare(
                        "INSERT INTO dietas_base
                           (id_cliente, id_dietista, nombre, icono, color,
                            kcal_objetivo, prot_objetivo, carb_objetivo, grasas_objetivo)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                    $stmt->bind_param('iisssiiii',
                        $idCliente, $uid, $nombreFinal, $plantilla['icono'], $plantilla['color'],
                        $plantilla['kcal_objetivo'], $plantilla['prot_objetivo'],
                        $plantilla['carb_objetivo'], $plantilla['grasas_objetivo']);
                    $stmt->execute();
                    $nuevaDietaId = $stmt->insert_id;
                    $stmt->close();

                    // Clonar bloques + alimentos
                    $stmt = $conn->prepare("SELECT id, nombre_bloque, orden FROM comidas_bloques WHERE id_dieta=?");
                    $stmt->bind_param('i', $idPlantilla);
                    $stmt->execute();
                    $bloquesOrig = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();

                    foreach ($bloquesOrig as $bo) {
                        $stmt = $conn->prepare("INSERT INTO comidas_bloques (id_dieta, nombre_bloque, orden) VALUES (?, ?, ?)");
                        $stmt->bind_param('isi', $nuevaDietaId, $bo['nombre_bloque'], $bo['orden']);
                        $stmt->execute();
                        $nuevoBloqueId = $stmt->insert_id;
                        $stmt->close();

                        $stmt = $conn->prepare("SELECT id_alimento, cantidad_gr FROM dieta_alimentos WHERE id_bloque=?");
                        $stmt->bind_param('i', $bo['id']);
                        $stmt->execute();
                        $alimsOrig = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        $stmt->close();

                        foreach ($alimsOrig as $ao) {
                            $stmt = $conn->prepare("INSERT INTO dieta_alimentos (id_bloque, id_alimento, cantidad_gr) VALUES (?, ?, ?)");
                            $stmt->bind_param('iid', $nuevoBloqueId, $ao['id_alimento'], $ao['cantidad_gr']);
                            $stmt->execute();
                            $stmt->close();
                        }
                    }

                    $conn->commit();
                    header('Location: dietista_dietas.php?id=' . $nuevaDietaId);
                    exit;
                } catch (Exception $ex) {
                    $conn->rollback();
                    $error = 'Error al clonar: ' . $ex->getMessage();
                }
            }
        }
        elseif ($accion === 'borrar_plantilla') {
            $idP = (int)$_POST['id_plantilla'];
            $stmt = $conn->prepare("DELETE FROM dietas_base WHERE id=? AND id_dietista=? AND id_cliente IS NULL");
            $stmt->bind_param('ii', $idP, $uid);
            $stmt->execute();
            $ok = $stmt->affected_rows > 0 ? 'Plantilla borrada.' : 'No se pudo borrar.';
            $stmt->close();
        }
    }
}

// ============================================================
// Listado de plantillas
// ============================================================
$stmt = $conn->prepare(
    "SELECT id, nombre, icono, color, kcal_objetivo, prot_objetivo, carb_objetivo, grasas_objetivo
     FROM dietas_base
     WHERE id_dietista=? AND id_cliente IS NULL
     ORDER BY nombre ASC"
);
$stmt->bind_param('i', $uid);
$stmt->execute();
$plantillas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Clientes para "aplicar a"
$stmt = $conn->prepare("SELECT id, nombre_completo FROM usuarios WHERE rol='cliente' AND id_dietista=? AND activo=1 ORDER BY nombre_completo");
$stmt->bind_param('i', $uid);
$stmt->execute();
$clientes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$base   = '../../';
$active = 'plantillas';
$titulo = 'Plantillas';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main class="page">

  <?php if ($ok):    ?><div class="alert alert-success" role="status"><?= e($ok) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"  role="alert"><?= e($error) ?></div><?php endif; ?>

  <!-- Nueva plantilla -->
  <form method="post" class="card">
    <h2 class="card-title">➕ Nueva plantilla</h2>
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="accion" value="nueva_plantilla">

    <label class="field">
      <span class="field-label">Nombre</span>
      <input class="field-input" type="text" name="nombre" placeholder="Ej: Plantilla volumen 3000 kcal" required>
    </label>

    <div class="grid-2">
      <label class="field">
        <span class="field-label">Icono</span>
        <input class="field-input" type="text" name="icono" value="📑" maxlength="6">
      </label>
      <label class="field">
        <span class="field-label">Color</span>
        <input class="field-input" type="color" name="color" value="#2F9E73">
      </label>
    </div>

    <div class="grid-2">
      <label class="field"><span class="field-label">Kcal</span><input class="field-input" type="number" min="0" name="kcal_objetivo" value="2000"></label>
      <label class="field"><span class="field-label">Prot (g)</span><input class="field-input" type="number" min="0" name="prot_objetivo" value="150"></label>
      <label class="field"><span class="field-label">Carbos (g)</span><input class="field-input" type="number" min="0" name="carb_objetivo" value="200"></label>
      <label class="field"><span class="field-label">Grasas (g)</span><input class="field-input" type="number" min="0" name="grasas_objetivo" value="70"></label>
    </div>

    <button type="submit" class="btn btn-primary btn-block">Crear plantilla y editar bloques</button>
  </form>

  <!-- Listado -->
  <section class="card">
    <h3 class="card-title">📑 <?= count($plantillas) ?> plantilla<?= count($plantillas) === 1 ? '' : 's' ?></h3>

    <?php if (!$plantillas): ?>
      <p class="text-muted">Aún no tienes plantillas.</p>
    <?php else: ?>
      <ul class="dt-clients" role="list">
        <?php foreach ($plantillas as $p): ?>
          <li class="dt-client">
            <a class="dt-client-link" href="dietista_dietas.php?id=<?= (int)$p['id'] ?>">
              <div class="dash-diet-icon"><?= e($p['icono'] ?: '📑') ?></div>
              <div class="dt-client-info">
                <div class="dt-client-name"><?= e($p['nombre']) ?></div>
                <div class="dt-client-meta">
                  <span><?= (int)$p['kcal_objetivo'] ?> kcal</span>
                  <span class="text-muted">P<?= (int)$p['prot_objetivo'] ?> · C<?= (int)$p['carb_objetivo'] ?> · G<?= (int)$p['grasas_objetivo'] ?></span>
                </div>
              </div>
            </a>

            <details class="cons-details">
              <summary>📥 Aplicar a cliente</summary>
              <form method="post" class="ptl-aplicar">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="accion" value="aplicar">
                <input type="hidden" name="id_plantilla" value="<?= (int)$p['id'] ?>">

                <label class="field">
                  <span class="field-label">Cliente</span>
                  <select class="field-select" name="id_cliente" required>
                    <option value="">— Elegir —</option>
                    <?php foreach ($clientes as $c): ?>
                      <option value="<?= (int)$c['id'] ?>"><?= e($c['nombre_completo']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label class="field">
                  <span class="field-label">Nuevo nombre (opcional)</span>
                  <input class="field-input" type="text" name="nuevo_nombre" placeholder="Heredará el de la plantilla si lo dejas vacío">
                </label>
                <div class="form-actions">
                  <button type="submit" class="btn btn-primary btn-block">Crear dieta para el cliente</button>
                  <button type="button" class="btn btn-danger btn-mini" onclick="if(confirm('¿Borrar la plantilla?')){this.closest('details').nextElementSibling.submit();}">🗑️ Borrar plantilla</button>
                </div>
              </form>
            </details>
            <form method="post" class="inline-form" hidden>
              <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="accion" value="borrar_plantilla">
              <input type="hidden" name="id_plantilla" value="<?= (int)$p['id'] ?>">
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>