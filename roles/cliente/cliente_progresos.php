<?php
require_once __DIR__ . '/../../includes/conexion.php';
requiere_rol('cliente', '../../');

$uid    = usuario_id();
$ok     = '';
$error  = '';

// Convierte "89,5" o "89.5" a float. Devuelve null si está vacío.
function num_dec($v) {
    if ($v === '' || $v === null) return null;
    return (float)str_replace(',', '.', (string)$v);
}

$camposMedidas = [
    'cintura'    => 'Cintura',
    'cadera'     => 'Cadera',
    'pecho'      => 'Pecho',
    'cuello'     => 'Cuello',
    'hombros'    => 'Hombros',
    'brazo_izq'  => 'Brazo izq.',
    'brazo_der'  => 'Brazo der.',
    'muslo_izq'  => 'Muslo izq.',
    'muslo_der'  => 'Muslo der.',
    'pantorrilla'=> 'Pantorrilla',
];
$tiposFoto = [
    'foto_frontal' => 'Frontal',
    'foto_perfil'  => 'Perfil',
    'foto_espalda' => 'Espalda',
];

// ============================================================
// POST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $error = 'Sesión expirada. Recarga la página.';
    } else {
        $accion = $_POST['accion'] ?? '';

        if ($accion === 'guardar') {
            $fecha = $_POST['fecha_hora'] ?? date('Y-m-d H:i:s');
            $fecha = str_replace('T', ' ', $fecha);
            if (strlen($fecha) === 16) $fecha .= ':00';
            $fechaDate = substr($fecha, 0, 10);

            $peso  = (float)str_replace(',', '.', (string)($_POST['peso_kg'] ?? 0));
            $grasa = num_dec($_POST['porcentaje_grasa'] ?? '');
            $pasos = ($_POST['pasos'] ?? '') === '' ? null : (int)$_POST['pasos'];
            $notas = trim($_POST['notas_cliente'] ?? '');

            if ($peso < 20 || $peso > 400) {
                $error = 'El peso debe estar entre 20 y 400 kg.';
            } else {
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare(
                        "INSERT INTO progresos_metricas (id_cliente, fecha_hora, peso_kg, porcentaje_grasa, pasos, notas_cliente)
                         VALUES (?, ?, ?, ?, ?, ?)"
                    );
                    $stmt->bind_param('isddis', $uid, $fecha, $peso, $grasa, $pasos, $notas);
                    $stmt->execute();
                    $stmt->close();

                    $hayMedida = false;
                    $vals = [];
                    foreach ($camposMedidas as $col => $_) {
                        $vals[$col] = num_dec($_POST[$col] ?? '');
                        if ($vals[$col] !== null) $hayMedida = true;
                    }
                    if ($hayMedida) {
                        $stmt = $conn->prepare(
                            "INSERT INTO medidas_corporales
                               (id_cliente, fecha, cintura, cadera, pecho, cuello, hombros,
                                brazo_izq, brazo_der, muslo_izq, muslo_der, pantorrilla, notas)
                             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
                        );
                        $stmt->bind_param('isdddddddddds',
                            $uid, $fechaDate,
                            $vals['cintura'], $vals['cadera'], $vals['pecho'], $vals['cuello'],
                            $vals['hombros'], $vals['brazo_izq'], $vals['brazo_der'],
                            $vals['muslo_izq'], $vals['muslo_der'], $vals['pantorrilla'], $notas);
                        $stmt->execute();
                        $stmt->close();
                    }

                    if (!empty($_FILES['archivo']) && $_FILES['archivo']['error'] === UPLOAD_ERR_OK) {
                        $tipo = $_POST['tipo_foto'] ?? 'foto_frontal';
                        if (!isset($tiposFoto[$tipo])) $tipo = 'foto_frontal';

                        $maxMB = 8;
                        $file  = $_FILES['archivo'];
                        if ($file['size'] > $maxMB * 1024 * 1024) {
                            throw new Exception("La foto supera los {$maxMB} MB.");
                        }
                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        $mime  = $finfo->file($file['tmp_name']);
                        $extOk = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp'];
                        if (!isset($extOk[$mime])) throw new Exception('Formato no permitido (JPG/PNG/WEBP).');

                        $ext     = $extOk[$mime];
                        $dirRel  = 'uploads/fotos/' . $uid . '/';
                        $dirAbs  = __DIR__ . '/../../' . $dirRel;
                        if (!is_dir($dirAbs)) @mkdir($dirAbs, 0775, true);

                        $nombre  = $tipo . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                        $rutaAbs = $dirAbs . $nombre;
                        $rutaRel = $dirRel . $nombre;

                        if (!move_uploaded_file($file['tmp_name'], $rutaAbs)) {
                            throw new Exception('No se pudo guardar la foto.');
                        }

                        $stmt = $conn->prepare("INSERT INTO archivos_boveda (id_cliente, tipo, archivo_url) VALUES (?, ?, ?)");
                        $stmt->bind_param('iss', $uid, $tipo, $rutaRel);
                        $stmt->execute();
                        $stmt->close();
                    }

                    $conn->commit();
                    $ok = '¡Registro guardado correctamente!';
                } catch (Exception $ex) {
                    $conn->rollback();
                    $error = $ex->getMessage();
                }
            }
        }
        elseif ($accion === 'borrar') {
            $idDel = (int)($_POST['id'] ?? 0);
            $stmt = $conn->prepare("DELETE FROM progresos_metricas WHERE id = ? AND id_cliente = ?");
            $stmt->bind_param('ii', $idDel, $uid);
            $ok = $stmt->execute() ? 'Registro borrado.' : 'No se pudo borrar.';
            $stmt->close();
        }
    }
}

// ============================================================
// Datos
// ============================================================
$stmt = $conn->prepare(
    "SELECT id, fecha_hora, peso_kg, porcentaje_grasa, pasos, notas_cliente
     FROM progresos_metricas
     WHERE id_cliente = ?
     ORDER BY fecha_hora ASC"
);
$stmt->bind_param('i', $uid);
$stmt->execute();
$todos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalRegs = count($todos);
$primero   = $todos[0]              ?? null;
$ultimo    = $todos[$totalRegs - 1] ?? null;
$variacion = ($primero && $ultimo) ? ((float)$ultimo['peso_kg'] - (float)$primero['peso_kg']) : 0;

$base   = '../../';
$active = 'progresos';
$titulo = 'Progresos';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main class="page">

  <?php if ($ok):    ?><div class="alert alert-success" role="status"><?= e($ok) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"  role="alert"><?= e($error) ?></div><?php endif; ?>

  <!-- RESUMEN -->
  <section class="card">
    <h2 class="card-title">📈 Resumen</h2>
    <?php if (!$ultimo): ?>
      <p class="text-muted">Aún no has registrado tu peso.</p>
    <?php else: ?>
      <div class="prog-stats">
        <div class="prog-stat">
          <span class="prog-stat-num"><?= e(rtrim(rtrim((string)$ultimo['peso_kg'], '0'), '.')) ?> kg</span>
          <span class="prog-stat-lbl">Actual</span>
        </div>
        <div class="prog-stat">
          <span class="prog-stat-num"><?= e(rtrim(rtrim((string)$primero['peso_kg'], '0'), '.')) ?> kg</span>
          <span class="prog-stat-lbl">Inicial</span>
        </div>
        <div class="prog-stat">
          <span class="prog-stat-num <?= $variacion < 0 ? 'is-down' : ($variacion > 0 ? 'is-up' : '') ?>">
            <?= ($variacion > 0 ? '+' : '') . number_format($variacion, 1, ',', '') ?> kg
          </span>
          <span class="prog-stat-lbl">Variación</span>
        </div>
        <div class="prog-stat">
          <span class="prog-stat-num"><?= (int)$totalRegs ?></span>
          <span class="prog-stat-lbl">Registros</span>
        </div>
      </div>
    <?php endif; ?>
  </section>

  <!-- GRÁFICA -->
  <?php if ($totalRegs >= 1): ?>
    <section class="card">
      <h2 class="card-title">📊 Evolución del peso</h2>
      <div class="ficha-peso-chart-wrap">
        <canvas id="pesoChart" aria-label="Evolución del peso"></canvas>
      </div>
      <?php if ($totalRegs >= 2): ?>
        <button type="button" class="btn btn-outline btn-block ficha-peso-chart-btn" id="btnPesoVerMas">📈 Ver gráfica detallada</button>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <!-- NUEVO REGISTRO -->
  <form method="post" enctype="multipart/form-data" class="card">
    <h2 class="card-title">➕ Nuevo registro de progreso</h2>
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="accion" value="guardar">

    <label class="field">
      <span class="field-label">Fecha y hora</span>
      <input class="field-input" type="datetime-local" name="fecha_hora"
             value="<?= e(date('Y-m-d\TH:i')) ?>" required>
    </label>

    <div class="grid-2">
      <label class="field">
        <span class="field-label">Peso (kg) <span class="text-muted">*</span></span>
        <input class="field-input" type="text" inputmode="decimal"
               name="peso_kg" required placeholder="Ej: 78,5"
               pattern="[0-9]+([.,][0-9]+)?"
               title="Usa coma o punto. Ej: 78,5">
      </label>
      <label class="field">
        <span class="field-label">% grasa <span class="text-muted">(opcional)</span></span>
        <input class="field-input" type="text" inputmode="decimal"
               name="porcentaje_grasa" placeholder="Ej: 18,2"
               pattern="[0-9]+([.,][0-9]+)?"
               title="Usa coma o punto. Ej: 18,2">
      </label>
    </div>

    <div class="grid-2">
      <label class="field">
        <span class="field-label">Pasos del día <span class="text-muted">(opcional)</span></span>
        <input class="field-input" type="number" min="0" max="100000"
               name="pasos" inputmode="numeric" placeholder="Ej: 8500">
      </label>
      <label class="field">
        <span class="field-label">Nota <span class="text-muted">(opcional)</span></span>
        <input class="field-input" type="text" name="notas_cliente" maxlength="500"
               placeholder="¿Cómo te encontraste hoy?">
      </label>
    </div>

    <details class="prog-extra">
      <summary class="prog-extra-summary">📏 Añadir medidas corporales <span class="text-muted">(opcional)</span></summary>
      <div class="prog-extra-body">
        <div class="medidas-grid">
          <?php foreach ($camposMedidas as $col => $label): ?>
            <label class="field">
              <span class="field-label"><?= e($label) ?> <span class="text-muted">(cm)</span></span>
              <input class="field-input" type="text" inputmode="decimal"
                     name="<?= e($col) ?>" pattern="[0-9]+([.,][0-9]+)?"
                     title="Usa coma o punto. Ej: 32,5">
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    </details>

    <details class="prog-extra">
      <summary class="prog-extra-summary">📸 Adjuntar foto <span class="text-muted">(opcional)</span></summary>
      <div class="prog-extra-body">
        <label class="field">
          <span class="field-label">Tipo</span>
          <select class="field-select" name="tipo_foto">
            <?php foreach ($tiposFoto as $k => $v): ?>
              <option value="<?= e($k) ?>"><?= e($v) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field">
          <span class="field-label">Archivo (JPG, PNG o WEBP, máx. 8 MB)</span>
          <input class="field-input" type="file" name="archivo"
                 accept="image/jpeg,image/png,image/webp" capture="environment">
        </label>
      </div>
    </details>

    <button type="submit" class="btn btn-primary btn-block">Guardar registro</button>
  </form>

  <!-- HISTÓRICO -->
  <section class="card">
    <h2 class="card-title">📋 Histórico de peso</h2>
    <?php if (!$todos): ?>
      <p class="text-muted">Aún no hay registros.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Peso</th>
              <th>% grasa</th>
              <th>Pasos</th>
              <th class="td-actions"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach (array_reverse($todos) as $r): ?>
              <tr>
                <td><strong><?= e(date('d/m/Y H:i', strtotime($r['fecha_hora']))) ?></strong></td>
                <td><?= e(rtrim(rtrim((string)$r['peso_kg'], '0'), '.')) ?> kg</td>
                <td><?= $r['porcentaje_grasa'] !== null ? e(rtrim(rtrim((string)$r['porcentaje_grasa'], '0'), '.')) . ' %' : '—' ?></td>
                <td><?= $r['pasos'] !== null ? number_format((int)$r['pasos'], 0, ',', '.') : '—' ?></td>
                <td class="td-actions">
                  <form method="post" class="inline-form" onsubmit="return confirm('¿Borrar este registro?');">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="accion" value="borrar">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button type="submit" class="btn btn-ghost btn-mini" aria-label="Borrar">🗑️</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="text-muted prog-extra-hint">
        Para gestionar medidas y fotos individualmente puedes ir a
        <a href="cliente_medidas.php">Medidas</a> · <a href="cliente_fotos.php">Fotos</a>.
      </p>
    <?php endif; ?>
  </section>

</main>

<!-- Modal gráfica detallada -->
<?php if ($totalRegs >= 2): ?>
<div id="modal_peso_chart" class="modal-backdrop" hidden>
  <div class="modal modal-wide" role="dialog" aria-modal="true">
    <div class="modal-head">
      <h3 class="modal-title">📈 Evolución completa del peso</h3>
      <button type="button" class="modal-close" id="modal_peso_close" aria-label="Cerrar">✕</button>
    </div>
    <div class="modal-body">
      <div class="ficha-peso-chart-full">
        <canvas id="pesoChartFull"></canvas>
      </div>
      <ul class="ficha-peso-tabla" role="list">
        <?php foreach (array_reverse($todos) as $r): ?>
          <li class="ficha-peso-tabla-row">
            <span class="ficha-peso-tabla-fecha"><?= e(date('d/m/Y H:i', strtotime($r['fecha_hora']))) ?></span>
            <span class="ficha-peso-tabla-peso"><strong><?= e(rtrim(rtrim((string)$r['peso_kg'], '0'), '.')) ?></strong> kg</span>
            <span class="ficha-peso-tabla-grasa">
              <?= $r['porcentaje_grasa'] !== null ? e(rtrim(rtrim((string)$r['porcentaje_grasa'], '0'), '.')) . '%' : '—' ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
window.PROG_DATA = {
  pesos: <?= json_encode(array_map(function($r){
    return ['fecha'=>$r['fecha_hora'],'peso'=>(float)$r['peso_kg'],'grasa'=>$r['porcentaje_grasa']!==null?(float)$r['porcentaje_grasa']:null];
  }, $todos), JSON_UNESCAPED_UNICODE) ?>
};
</script>
<?php if ($totalRegs >= 1): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
  'use strict';
  const D = window.PROG_DATA;
  const $ = id => document.getElementById(id);

  function makePesoChart(canvasId, detailed) {
    if (typeof Chart === 'undefined') return null;
    const ctx = $(canvasId);
    if (!ctx || !D.pesos || D.pesos.length === 0) return null;

    const labels  = D.pesos.map(p => new Date(p.fecha).toLocaleDateString('es-ES', { day:'2-digit', month:'2-digit' }));
    const dataPeso = D.pesos.map(p => p.peso);
    const hasGrasa = D.pesos.some(p => p.grasa !== null);
    const dataGrasa = hasGrasa ? D.pesos.map(p => p.grasa) : null;

    const datasets = [{
      label: 'Peso (kg)', data: dataPeso,
      borderColor: '#2F9E73', backgroundColor: 'rgba(47,158,115,0.12)',
      borderWidth: 2, pointRadius: detailed ? 4 : 3, pointHoverRadius: 6,
      tension: 0.3, fill: true, yAxisID: 'y'
    }];
    if (detailed && hasGrasa) {
      datasets.push({
        label: '% grasa', data: dataGrasa,
        borderColor: '#E0B628', backgroundColor: 'rgba(224,182,40,0.10)',
        borderWidth: 2, pointRadius: 3, pointHoverRadius: 6,
        tension: 0.3, fill: false, spanGaps: true, yAxisID: 'y1'
      });
    }

    const scales = {
      x: { grid: { display: !!detailed, color: '#E2E8E2' }, ticks: { font: { size: detailed ? 11 : 10 }, maxRotation: 0, autoSkip: true, maxTicksLimit: detailed ? 12 : 5 } },
      y: { position: 'left', grid: { color: '#E2E8E2' }, ticks: { font: { size: detailed ? 11 : 10 }, callback: v => v + ' kg' } }
    };
    if (detailed && hasGrasa) {
      scales.y1 = { position: 'right', grid: { drawOnChartArea: false }, ticks: { font: { size: 11 }, callback: v => v + '%' } };
    }

    return new Chart(ctx, {
      type: 'line',
      data: { labels, datasets },
      options: {
        responsive: true, maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: !!detailed, position: 'bottom', labels: { font: { size: 12 }, boxWidth: 12 } },
          tooltip: { callbacks: { title: items => new Date(D.pesos[items[0].dataIndex].fecha).toLocaleString('es-ES') } }
        },
        scales
      }
    });
  }

  let chartFull = null;
  document.addEventListener('DOMContentLoaded', () => {
    makePesoChart('pesoChart', false);

    const btnVer = $('btnPesoVerMas');
    const modalP = $('modal_peso_chart');
    if (btnVer && modalP) {
      btnVer.addEventListener('click', () => {
        modalP.hidden = false; document.body.style.overflow = 'hidden';
        setTimeout(() => { if (!chartFull) chartFull = makePesoChart('pesoChartFull', true); else chartFull.resize(); }, 50);
      });
      $('modal_peso_close').addEventListener('click', () => { modalP.hidden = true; document.body.style.overflow = ''; });
      modalP.addEventListener('click', e => { if (e.target === modalP) { modalP.hidden = true; document.body.style.overflow = ''; } });
      document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modalP.hidden) { modalP.hidden = true; document.body.style.overflow = ''; } });
    }
  });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>