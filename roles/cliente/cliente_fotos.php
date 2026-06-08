<?php
require_once __DIR__ . '/../../includes/conexion.php';
requiere_rol('cliente', '../../');

$uid   = usuario_id();
$ok    = '';
$error = '';

$tiposPermitidos = [
    'foto_frontal' => 'Foto frontal',
    'foto_perfil'  => 'Foto de perfil',
    'foto_espalda' => 'Foto de espalda',
];

$dirRel = 'uploads/fotos/' . $uid . '/';
$dirAbs = __DIR__ . '/../../' . $dirRel;
if (!is_dir($dirAbs)) {
    @mkdir($dirAbs, 0775, true);
}

// --- POST: subir / borrar ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $error = 'Sesión expirada. Recarga la página.';
    } else {
        $accion = $_POST['accion'] ?? '';

        if ($accion === 'subir') {
            $tipo = $_POST['tipo'] ?? '';
            if (!isset($tiposPermitidos[$tipo])) {
                $error = 'Tipo de foto no válido.';
            } elseif (empty($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
                $error = 'No se subió ningún archivo o hubo un error.';
            } else {
                $file = $_FILES['archivo'];
                $maxMB = 8;
                if ($file['size'] > $maxMB * 1024 * 1024) {
                    $error = "La foto supera los {$maxMB} MB.";
                } else {
                  // Validar mime básico
                  $mime = $_FILES['archivo']['type'];
                  $extOk = [
                      'image/jpeg' => 'jpg',
                      'image/png'  => 'png',
                      'image/webp' => 'webp',
                  ];
                    if (!isset($extOk[$mime])) {
                        $error = 'Formato no permitido. Sube JPG, PNG o WEBP.';
                    } else {
                        $ext      = $extOk[$mime];
                        $nombre   = $tipo . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                        $rutaAbs  = $dirAbs . $nombre;
                        $rutaRel  = $dirRel . $nombre;

                        if (move_uploaded_file($file['tmp_name'], $rutaAbs)) {
                            $stmt = $conn->prepare(
                                "INSERT INTO archivos_boveda (id_cliente, tipo, archivo_url) VALUES (?, ?, ?)"
                            );
                            $stmt->bind_param('iss', $uid, $tipo, $rutaRel);
                            $ok = $stmt->execute() ? 'Foto subida.' : 'No se pudo guardar en la BD.';
                            $stmt->close();
                        } else {
                            $error = 'No se pudo guardar el archivo (¿permisos de la carpeta?).';
                        }
                    }
                }
            }
        }
        elseif ($accion === 'borrar') {
            $idDel = (int)($_POST['id'] ?? 0);
            $stmt = $conn->prepare("SELECT archivo_url FROM archivos_boveda WHERE id = ? AND id_cliente = ?");
            $stmt->bind_param('ii', $idDel, $uid);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row) {
                $rutaBorrar = __DIR__ . '/../../' . $row['archivo_url'];
                if (is_file($rutaBorrar)) @unlink($rutaBorrar);

                $stmt = $conn->prepare("DELETE FROM archivos_boveda WHERE id = ? AND id_cliente = ?");
                $stmt->bind_param('ii', $idDel, $uid);
                $ok = $stmt->execute() ? 'Foto eliminada.' : 'No se pudo borrar.';
                $stmt->close();
            } else {
                $error = 'Foto no encontrada.';
            }
        }
    }
}

// --- Cargar fotos agrupadas por tipo ---
$stmt = $conn->prepare(
    "SELECT id, tipo, archivo_url, fecha_subida
     FROM archivos_boveda
     WHERE id_cliente = ? AND tipo IN ('foto_frontal','foto_perfil','foto_espalda')
     ORDER BY fecha_subida DESC"
);
$stmt->bind_param('i', $uid);
$stmt->execute();
$todasFotos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$fotosPorTipo = ['foto_frontal'=>[], 'foto_perfil'=>[], 'foto_espalda'=>[]];
foreach ($todasFotos as $f) {
    $fotosPorTipo[$f['tipo']][] = $f;
}

$base   = '../../';
$active = 'fotos';
$titulo = 'Fotos';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main class="page">

  <?php if ($ok):    ?><div class="alert alert-success" role="status"><?= e($ok) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"  role="alert"><?= e($error) ?></div><?php endif; ?>

  <!-- SUBIR -->
  <form method="post" enctype="multipart/form-data" class="card">
    <h2 class="card-title">📸 Subir nueva foto</h2>
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="accion" value="subir">

    <label class="field">
      <span class="field-label">Tipo</span>
      <select class="field-select" name="tipo" required>
        <?php foreach ($tiposPermitidos as $k => $v): ?>
          <option value="<?= e($k) ?>"><?= e($v) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label class="field">
      <span class="field-label">Archivo (JPG, PNG o WEBP, máx. 8 MB)</span>
      <input class="field-input" type="file" name="archivo" accept="image/jpeg,image/png,image/webp" capture="environment" required>
    </label>

    <button type="submit" class="btn btn-primary btn-block">Subir foto</button>
  </form>

  <!-- GALERÍAS POR TIPO -->
  <?php foreach ($tiposPermitidos as $tipo => $label):
    $lista = $fotosPorTipo[$tipo]; ?>
    <section class="card">
      <h3 class="card-title"><?= e($label) ?> <span class="text-muted">(<?= count($lista) ?>)</span></h3>

      <?php if (!$lista): ?>
        <p class="text-muted">Aún no has subido fotos de este tipo.</p>
      <?php else: ?>
        <div class="photo-grid">
          <?php foreach ($lista as $f): ?>
            <figure class="photo-card">
              <a href="<?= e($base . $f['archivo_url']) ?>" target="_blank" rel="noopener">
                <img src="<?= e($base . $f['archivo_url']) ?>" alt="<?= e($label) ?>" loading="lazy">
              </a>
              <figcaption class="photo-cap">
                <span><?= e(date('d/m/Y', strtotime($f['fecha_subida']))) ?></span>
                <form method="post" class="inline-form" onsubmit="return confirm('¿Borrar esta foto?');">
                  <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="accion" value="borrar">
                  <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                  <button type="submit" class="btn btn-ghost btn-mini" aria-label="Borrar">🗑️</button>
                </form>
              </figcaption>
            </figure>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>

</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>