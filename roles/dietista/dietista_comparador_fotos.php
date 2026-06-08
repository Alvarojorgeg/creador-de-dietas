<?php
require_once __DIR__ . '/../../includes/conexion.php';
requiere_rol('dietista', '../../');

$uid = usuario_id();
$idC = isset($_GET['id_cliente']) ? (int)$_GET['id_cliente'] : 0;

$cliente = null;
if ($idC > 0) {
    $stmt = $conn->prepare("SELECT id, nombre_completo FROM usuarios WHERE id=? AND id_dietista=? AND rol='cliente' AND activo=1");
    $stmt->bind_param('ii', $idC, $uid);
    $stmt->execute();
    $cliente = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$cliente) { header('Location: dietista_comparador_fotos.php'); exit; }
}

// ============================================================
// MODO LISTADO
// ============================================================
if (!$cliente) {
    $stmt = $conn->prepare(
        "SELECT u.id, u.nombre_completo,
                (SELECT COUNT(*) FROM archivos_boveda WHERE id_cliente=u.id AND tipo IN('foto_frontal','foto_perfil','foto_espalda')) AS n
         FROM usuarios u
         WHERE u.rol='cliente' AND u.id_dietista=? AND u.activo=1
         ORDER BY u.nombre_completo"
    );
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $lista = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $base   = '../../';
    $active = 'fotos';
    $titulo = 'Comparador de fotos';
    include __DIR__ . '/../../includes/sidebar.php';
    ?>
    <main class="page">
      <section class="card">
        <h2 class="card-title">📸 Selecciona un cliente</h2>
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
                      <span><?= (int)$c['n'] ?> foto<?= (int)$c['n'] === 1 ? '' : 's' ?></span>
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
// COMPARADOR DE UN CLIENTE (?id_cliente=X)
// ============================================================
$tipoFiltro = $_GET['tipo'] ?? 'foto_frontal';
if (!in_array($tipoFiltro, ['foto_frontal','foto_perfil','foto_espalda'], true)) {
    $tipoFiltro = 'foto_frontal';
}

$stmt = $conn->prepare(
    "SELECT id, tipo, archivo_url, fecha_subida
     FROM archivos_boveda
     WHERE id_cliente=? AND tipo=?
     ORDER BY fecha_subida ASC"
);
$stmt->bind_param('is', $idC, $tipoFiltro);
$stmt->execute();
$fotos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$tipoLabels = [
    'foto_frontal' => 'Frontal',
    'foto_perfil'  => 'Perfil',
    'foto_espalda' => 'Espalda',
];

// IDs preseleccionados (?antes=X&despues=Y)
$idAntes   = isset($_GET['antes'])   ? (int)$_GET['antes']   : 0;
$idDespues = isset($_GET['despues']) ? (int)$_GET['despues'] : 0;

$fotoAntes = $fotoDespues = null;
foreach ($fotos as $f) {
    if ((int)$f['id'] === $idAntes)   $fotoAntes   = $f;
    if ((int)$f['id'] === $idDespues) $fotoDespues = $f;
}

$base   = '../../';
$active = 'fotos';
$titulo = $cliente['nombre_completo'];
include __DIR__ . '/../../includes/sidebar.php';
?>
<main class="page">

  <header class="ck-header">
    <p class="text-soft">Comparador de fotos de</p>
    <h2 class="h1"><?= e($cliente['nombre_completo']) ?></h2>
    <div class="form-actions">
      <a class="btn btn-outline btn-mini" href="dietista_comparador_fotos.php">← Otros clientes</a>
      <a class="btn btn-outline btn-mini" href="dietista_ficha.php?id=<?= $idC ?>">Ficha</a>
    </div>
  </header>

  <!-- Selector de tipo -->
  <div class="diet-switcher" role="tablist" aria-label="Tipo de foto">
    <?php foreach ($tipoLabels as $k => $lbl): ?>
      <a class="diet-switcher-btn<?= $tipoFiltro === $k ? ' is-active' : '' ?>"
         href="?id_cliente=<?= $idC ?>&tipo=<?= e($k) ?>"
         role="tab" aria-selected="<?= $tipoFiltro === $k ? 'true' : 'false' ?>">
        <?= e($lbl) ?>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- Comparación lado a lado -->
  <article class="card">
    <h3 class="card-title">Comparación</h3>
    <div class="cmp-pair">
      <figure class="cmp-side">
        <figcaption class="cmp-side-label">ANTES</figcaption>
        <?php if ($fotoAntes): ?>
          <img src="<?= e($base . $fotoAntes['archivo_url']) ?>" alt="Antes" loading="lazy">
          <div class="cmp-side-date"><?= e(date('d/m/Y', strtotime($fotoAntes['fecha_subida']))) ?></div>
        <?php else: ?>
          <div class="cmp-side-empty">Selecciona una foto ↓</div>
        <?php endif; ?>
      </figure>

      <figure class="cmp-side">
        <figcaption class="cmp-side-label">DESPUÉS</figcaption>
        <?php if ($fotoDespues): ?>
          <img src="<?= e($base . $fotoDespues['archivo_url']) ?>" alt="Después" loading="lazy">
          <div class="cmp-side-date"><?= e(date('d/m/Y', strtotime($fotoDespues['fecha_subida']))) ?></div>
        <?php else: ?>
          <div class="cmp-side-empty">Selecciona una foto ↓</div>
        <?php endif; ?>
      </figure>
    </div>
  </article>

  <!-- Galería con selección -->
  <article class="card">
    <h3 class="card-title"><?= e($tipoLabels[$tipoFiltro]) ?> · <?= count($fotos) ?> foto<?= count($fotos) === 1 ? '' : 's' ?></h3>

    <?php if (!$fotos): ?>
      <p class="text-muted">El cliente no ha subido fotos de este tipo.</p>
    <?php else: ?>
      <form method="get" class="cmp-form">
        <input type="hidden" name="id_cliente" value="<?= $idC ?>">
        <input type="hidden" name="tipo" value="<?= e($tipoFiltro) ?>">

        <div class="photo-grid">
          <?php foreach ($fotos as $f):
            $esAntes   = ((int)$f['id'] === $idAntes);
            $esDespues = ((int)$f['id'] === $idDespues);
          ?>
            <figure class="photo-card cmp-card<?= $esAntes ? ' is-antes' : '' ?><?= $esDespues ? ' is-despues' : '' ?>">
              <a href="<?= e($base . $f['archivo_url']) ?>" target="_blank" rel="noopener">
                <img src="<?= e($base . $f['archivo_url']) ?>" alt="" loading="lazy">
              </a>
              <figcaption class="photo-cap">
                <span><?= e(date('d/m/Y', strtotime($f['fecha_subida']))) ?></span>
              </figcaption>
              <div class="cmp-picker">
                <label class="cmp-radio">
                  <input type="radio" name="antes" value="<?= (int)$f['id'] ?>" <?= $esAntes ? 'checked' : '' ?>>
                  <span>Antes</span>
                </label>
                <label class="cmp-radio">
                  <input type="radio" name="despues" value="<?= (int)$f['id'] ?>" <?= $esDespues ? 'checked' : '' ?>>
                  <span>Después</span>
                </label>
              </div>
            </figure>
          <?php endforeach; ?>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary btn-block">Comparar fotos seleccionadas</button>
        </div>
      </form>
    <?php endif; ?>
  </article>

</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>