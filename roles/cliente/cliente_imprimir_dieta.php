<?php
require_once __DIR__ . '/../../includes/conexion.php';
requiere_rol('cliente', '../../');

$uid = usuario_id();
$idDieta = isset($_GET['dieta']) ? (int)$_GET['dieta'] : 0;
$autoprint = !empty($_GET['auto']);   // si ?auto=1 → imprime automáticamente

$stmt = $conn->prepare(
    "SELECT d.id, d.nombre, d.icono, d.kcal_objetivo, d.prot_objetivo, d.carb_objetivo, d.grasas_objetivo,
            u.nombre_completo AS dietista_nombre, c.nombre_completo AS cliente_nombre
     FROM dietas_base d
     JOIN usuarios u ON u.id = d.id_dietista
     JOIN usuarios c ON c.id = ?
     WHERE d.id = ? AND (d.id_cliente = ? OR d.id_cliente IS NULL OR EXISTS(
         SELECT 1 FROM calendario_asignaciones ca WHERE ca.id_dieta = d.id AND ca.id_cliente = ?
     ))
     LIMIT 1"
);
$stmt->bind_param('iiii', $uid, $idDieta, $uid, $uid);
$stmt->execute();
$dieta = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$dieta) { header('Location: cliente_dieta.php'); exit; }

$stmt = $conn->prepare(
    "SELECT b.id AS id_bloque, b.nombre_bloque, b.orden,
            da.cantidad_gr,
            a.nombre AS alimento, a.marca, a.racion_base_gr,
            a.kcal, a.proteinas, a.carbos, a.grasas
     FROM comidas_bloques b
     LEFT JOIN dieta_alimentos da ON da.id_bloque = b.id
     LEFT JOIN alimentos a ON a.id = da.id_alimento
     WHERE b.id_dieta = ?
     ORDER BY b.orden ASC, b.id ASC"
);
$stmt->bind_param('i', $idDieta);
$stmt->execute();
$res = $stmt->get_result();
$bloques = [];
while ($r = $res->fetch_assoc()) {
    $idb = (int)$r['id_bloque'];
    if (!isset($bloques[$idb])) {
        $bloques[$idb] = ['nombre' => $r['nombre_bloque'], 'alimentos' => [],
                          'total' => ['g'=>0,'kcal'=>0,'p'=>0,'c'=>0,'g_macro'=>0]];
    }
    if ($r['alimento']) {
        $cant = (float)$r['cantidad_gr'];
        $base = max(1, (float)$r['racion_base_gr']);
        $f    = $cant / $base;
        $kcal = round($r['kcal'] * $f);
        $p = round($r['proteinas'] * $f, 1);
        $c = round($r['carbos']    * $f, 1);
        $g = round($r['grasas']    * $f, 1);
        $bloques[$idb]['alimentos'][] = [
            'nombre'=>$r['alimento'], 'marca'=>$r['marca'],
            'gr'=>$cant, 'kcal'=>$kcal, 'p'=>$p, 'c'=>$c, 'g'=>$g
        ];
        $bloques[$idb]['total']['g']       += $cant;
        $bloques[$idb]['total']['kcal']    += $kcal;
        $bloques[$idb]['total']['p']       += $p;
        $bloques[$idb]['total']['c']       += $c;
        $bloques[$idb]['total']['g_macro'] += $g;
    }
}
$stmt->close();

$totalDia = ['kcal'=>0,'p'=>0,'c'=>0,'g'=>0];
foreach ($bloques as $b) {
    $totalDia['kcal'] += $b['total']['kcal'];
    $totalDia['p']    += $b['total']['p'];
    $totalDia['c']    += $b['total']['c'];
    $totalDia['g']    += $b['total']['g_macro'];
}
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>.</title>
<style>
@page { margin: 1.5cm; size: A4; }
html, body {
  background: #ffffff !important;
  color: #000000 !important;
  margin: 0; padding: 20px;
  font-family: Arial, Helvetica, sans-serif;
  font-size: 12px; line-height: 1.45;
  -webkit-print-color-adjust: exact;
  print-color-adjust: exact;
  color-scheme: light only;
}
* { color: inherit; box-sizing: border-box; }

.print-header { text-align: center; margin-bottom: 18px; }
.print-title { font-size: 18px; font-weight: bold; margin: 0 0 6px; }
.print-meta { font-size: 13px; margin: 1px 0; }
.print-meta strong { font-weight: bold; }

.print-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
.print-table th { text-align: left; font-weight: bold; padding: 4px 6px; border-bottom: 1.5px solid #000; font-size: 12px; }
.print-table th.num { text-align: right; }
.print-table td { padding: 3px 6px; font-size: 12px; border-bottom: 1px solid #E0E0E0; }
.print-table td.num { text-align: right; font-variant-numeric: tabular-nums; }
.print-table .section-row td { padding-top: 14px; padding-bottom: 4px; border-bottom: 1px solid #000; font-weight: bold; font-size: 13px; }
.print-table .obj-row td { padding-top: 4px; font-family: 'Courier New', Courier, monospace; font-size: 12px; border-bottom: none; }
.print-table .subtotal-row td { border-top: 1px solid #999; border-bottom: 1px solid #000; font-weight: bold; padding-top: 6px; padding-bottom: 6px; }

.print-totales { margin-top: 18px; padding-top: 8px; }
.print-totales-title { font-weight: bold; font-size: 13px; border-bottom: 1px solid #000; padding-bottom: 4px; margin-bottom: 6px; }
.print-totales-line { font-family: 'Courier New', Courier, monospace; font-size: 12px; }

@media print {
  body { padding: 0; }
  .print-table .section-row td { page-break-after: avoid; }
  .print-table tr { page-break-inside: avoid; }
}
</style>
</head>
<body>

<div class="print-header">
  <h1 class="print-title">Dieta - <?= e($dieta['nombre']) ?></h1>
  <div class="print-meta">
    <strong>Cliente:</strong> <?= e($dieta['cliente_nombre']) ?>
    &nbsp;|&nbsp;
    <strong>Dietista:</strong> <?= e($dieta['dietista_nombre']) ?>
  </div>
  <div class="print-meta">Fecha: <?= e(date('d/m/Y')) ?></div>
</div>

<table class="print-table">
  <thead>
    <tr>
      <th>Alimento</th>
      <th class="num">g</th>
      <th class="num">kcal</th>
      <th class="num">P</th>
      <th class="num">C</th>
      <th class="num">G</th>
    </tr>
  </thead>
  <tbody>
    <tr class="section-row"><td colspan="6">Objetivo nutricional</td></tr>
    <tr class="obj-row">
      <td colspan="6">
        Kcal: <?= (int)$dieta['kcal_objetivo'] ?>
        | Proteínas: <?= (int)$dieta['prot_objetivo'] ?>g
        | Carbohidratos: <?= (int)$dieta['carb_objetivo'] ?>g
        | Grasas: <?= (int)$dieta['grasas_objetivo'] ?>g
      </td>
    </tr>

    <?php $idx = 1; foreach ($bloques as $b): ?>
      <tr class="section-row"><td colspan="6"><?= $idx ?>. <?= e($b['nombre']) ?></td></tr>
      <?php foreach ($b['alimentos'] as $a): ?>
        <tr>
          <td><?= e($a['nombre']) ?></td>
          <td class="num"><?= (int)$a['gr'] ?></td>
          <td class="num"><?= (int)$a['kcal'] ?></td>
          <td class="num"><?= number_format($a['p'], 1, '.', '') ?></td>
          <td class="num"><?= number_format($a['c'], 1, '.', '') ?></td>
          <td class="num"><?= number_format($a['g'], 1, '.', '') ?></td>
        </tr>
      <?php endforeach; ?>
      <tr class="subtotal-row">
        <td>Subtotal comida:</td>
        <td class="num"><?= (int)$b['total']['g'] ?></td>
        <td class="num"><?= (int)$b['total']['kcal'] ?></td>
        <td class="num"><?= number_format($b['total']['p'], 1, '.', '') ?></td>
        <td class="num"><?= number_format($b['total']['c'], 1, '.', '') ?></td>
        <td class="num"><?= number_format($b['total']['g_macro'], 1, '.', '') ?></td>
      </tr>
    <?php $idx++; endforeach; ?>
  </tbody>
</table>

<div class="print-totales">
  <div class="print-totales-title">Totales del día</div>
  <div class="print-totales-line">
    Kcal: <?= (int)$totalDia['kcal'] ?> | P: <?= number_format($totalDia['p'], 1, '.', '') ?>g | C: <?= number_format($totalDia['c'], 1, '.', '') ?>g | G: <?= number_format($totalDia['g'], 1, '.', '') ?>g
  </div>
</div>

<?php if ($autoprint): ?>
<script>
  // Auto-imprimir al cargar (cuando se invoca via iframe con ?auto=1)
  window.addEventListener('load', function(){ setTimeout(function(){ window.focus(); window.print(); }, 100); });
</script>
<?php endif; ?>

</body>
</html>