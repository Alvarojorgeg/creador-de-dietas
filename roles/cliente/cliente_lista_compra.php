<?php
require_once __DIR__ . '/../../includes/conexion.php';
requiere_rol('cliente', '../../');

$uid = usuario_id();

// --- Rango de días: 3, 7 (def) o 14 ---
$rango = (int)($_GET['rango'] ?? 7);
if (!in_array($rango, [3, 7, 14], true)) $rango = 7;

$desde = new DateTime('today');
$hasta = (clone $desde)->modify('+' . ($rango - 1) . ' days');

// --- Agregación de alimentos en las dietas asignadas ---
$stmt = $conn->prepare(
    "SELECT a.id, a.nombre, a.marca, SUM(da.cantidad_gr) AS total_gr
     FROM calendario_asignaciones ca
     JOIN comidas_bloques  b  ON b.id_dieta   = ca.id_dieta
     JOIN dieta_alimentos  da ON da.id_bloque = b.id
     JOIN alimentos        a  ON a.id         = da.id_alimento
     WHERE ca.id_cliente = ?
       AND ca.fecha_asignada BETWEEN ? AND ?
     GROUP BY a.id, a.nombre, a.marca
     ORDER BY a.nombre ASC"
);
$d1 = $desde->format('Y-m-d');
$d2 = $hasta->format('Y-m-d');
$stmt->bind_param('iss', $uid, $d1, $d2);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$nombreCliente = $_SESSION['usuario_nombre'] ?? 'Cliente';

$base   = '../../';
$active = 'lista';
$titulo = 'Lista de la compra';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main class="page">

  <header class="list-header no-print">
    <h2 class="h1">🛒 Lista de la compra</h2>
    <p class="text-soft">
      Del <strong><?= e($desde->format('d/m')) ?></strong> al
      <strong><?= e($hasta->format('d/m/Y')) ?></strong>
      · <?= $rango ?> días
    </p>
  </header>

  <!-- Cabecera SOLO PARA IMPRESIÓN -->
  <header class="print-only print-shop-head">
    <h1>🛒 Lista de la compra · <?= e($nombreCliente) ?></h1>
    <p>Del <?= e($desde->format('d/m/Y')) ?> al <?= e($hasta->format('d/m/Y')) ?> · <?= $rango ?> días</p>
  </header>

  <!-- Selector de rango -->
  <div class="range-switch no-print" role="tablist" aria-label="Rango de días">
    <?php foreach ([3, 7, 14] as $r): ?>
      <a class="range-switch-btn<?= $r === $rango ? ' is-active' : '' ?>"
         href="?rango=<?= $r ?>"
         role="tab" aria-selected="<?= $r === $rango ? 'true' : 'false' ?>">
        <?= $r ?> días
      </a>
    <?php endforeach; ?>
  </div>

  <?php if (!$items): ?>
    <div class="card text-center">
      <p class="text-muted">No hay alimentos para este rango. ¿No tienes dietas asignadas en ese período?</p>
    </div>
  <?php else: ?>
    <article class="card">
      <div class="shop-bar no-print">
        <button type="button" class="btn btn-outline btn-ghost" id="btnLimpiarMarcas">Limpiar marcas</button>
        <button type="button" class="btn btn-primary" id="btnImprimirLista">🖨️ Imprimir</button>
      </div>

      <ul class="shop-list" id="shopList" data-key="lista_<?= (int)$uid ?>_<?= $rango ?>" role="list">
        <?php foreach ($items as $a):
          $total = (float)$a['total_gr'];
          $unidad = $total >= 1000 ? round($total / 1000, 2) . ' kg' : (int)$total . ' g';
        ?>
          <li class="shop-item">
            <label class="shop-item-row">
              <input type="checkbox" class="shop-check" data-id="<?= (int)$a['id'] ?>">
              <span class="shop-item-info">
                <span class="shop-item-name"><?= e($a['nombre']) ?></span>
                <?php if (!empty($a['marca'])): ?>
                  <span class="shop-item-brand"><?= e($a['marca']) ?></span>
                <?php endif; ?>
              </span>
              <span class="shop-item-qty"><?= e($unidad) ?></span>
            </label>
          </li>
        <?php endforeach; ?>
      </ul>
    </article>
  <?php endif; ?>

</main>

<script src="<?= e($base) ?>js/lista_compra.js" defer></script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
