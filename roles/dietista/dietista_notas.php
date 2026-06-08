<?php
require_once __DIR__ . '/../../includes/conexion.php';
requiere_rol('dietista', '../../');

$uid = usuario_id();

// Cargar notas iniciales (para SSR — la lista en vivo la mantiene notas.js)
$stmt = $conn->prepare(
    "SELECT id, contenido, color, fecha_actualizacion
     FROM notas_dietista
     WHERE id_dietista = ?
     ORDER BY fecha_actualizacion DESC"
);
$stmt->bind_param('i', $uid);
$stmt->execute();
$notasIniciales = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$base   = '../../';
$active = 'notas';
$titulo = 'Mis notas';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main class="page notas-page">

  <header class="notas-header">
    <div>
      <h2 class="h1">📌 Mis notas</h2>
      <p class="text-soft">Apuntes rápidos, recordatorios e ideas. Se guardan automáticamente.</p>
    </div>
    <button type="button" class="btn btn-primary notas-add-btn" id="btnAddNota" aria-label="Añadir nota">
      <span class="notas-add-icon">+</span>
      <span class="notas-add-text">Añadir nota</span>
    </button>
  </header>

  <div class="notas-grid" id="notasGrid"
       data-csrf="<?= e(csrf_token()) ?>"
       data-base="<?= e($base) ?>">

    <?php if (empty($notasIniciales)): ?>
      <div class="notas-empty" id="notasEmpty">
        <span class="notas-empty-icon" aria-hidden="true">📝</span>
        <p>Aún no tienes notas. Pulsa <strong>«+»</strong> para crear la primera.</p>
      </div>
    <?php else: ?>
      <?php foreach ($notasIniciales as $n): ?>
        <article class="nota nota--<?= e($n['color'] ?: 'amarillo') ?>" data-id="<?= (int)$n['id'] ?>">
          <header class="nota-head">
            <div class="nota-colors" role="group" aria-label="Cambiar color">
              <button type="button" class="nota-color nota-color--amarillo" data-c="amarillo" aria-label="Amarillo"></button>
              <button type="button" class="nota-color nota-color--rosa"     data-c="rosa"     aria-label="Rosa"></button>
              <button type="button" class="nota-color nota-color--azul"     data-c="azul"     aria-label="Azul"></button>
              <button type="button" class="nota-color nota-color--verde"    data-c="verde"    aria-label="Verde"></button>
              <button type="button" class="nota-color nota-color--naranja"  data-c="naranja"  aria-label="Naranja"></button>
              <button type="button" class="nota-color nota-color--lila"     data-c="lila"     aria-label="Lila"></button>
            </div>
            <button type="button" class="nota-del" aria-label="Borrar nota">✕</button>
          </header>
          <textarea class="nota-content" placeholder="Escribe aquí…" maxlength="5000"><?= e($n['contenido']) ?></textarea>
          <footer class="nota-foot">
            <span class="nota-status" aria-live="polite"></span>
            <span class="nota-fecha"><?= e(date('d/m H:i', strtotime($n['fecha_actualizacion']))) ?></span>
          </footer>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>

  </div>

</main>

<script src="<?= e($base) ?>js/notas.js" defer></script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
