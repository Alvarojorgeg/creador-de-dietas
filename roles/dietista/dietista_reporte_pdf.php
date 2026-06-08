<?php
require_once __DIR__ . '/../../includes/conexion.php';
requiere_rol('dietista', '../../');

$uid = usuario_id();
$idC = isset($_GET['id_cliente']) ? (int)$_GET['id_cliente'] : 0;

// ----- Rango de fechas + nota del dietista (Feature: reporte personalizado) -----
$desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-30 days'));
$hasta = $_GET['hasta'] ?? date('Y-m-d');
$nota  = trim((string)($_GET['nota'] ?? ''));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) $desde = date('Y-m-d', strtotime('-30 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) $hasta = date('Y-m-d');
if (strtotime($desde) > strtotime($hasta)) { $tmp = $desde; $desde = $hasta; $hasta = $tmp; }
$nota = mb_substr($nota, 0, 2000);

$desdeIni = $desde . ' 00:00:00';
$hastaFin = $hasta . ' 23:59:59';

// ----- Verificación de cliente -----
$cliente = null;
if ($idC > 0) {
    $stmt = $conn->prepare(
        "SELECT id, nombre_completo, email, fecha_registro
         FROM usuarios WHERE id=? AND id_dietista=? AND rol='cliente' AND activo=1"
    );
    $stmt->bind_param('ii', $idC, $uid);
    $stmt->execute();
    $cliente = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// ----- Si no hay cliente: selector -----
if (!$cliente) {
    $stmt = $conn->prepare(
        "SELECT id, nombre_completo FROM usuarios
         WHERE rol='cliente' AND id_dietista=? AND activo=1
         ORDER BY nombre_completo"
    );
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $lista = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $base   = '../../';
    $active = 'reporte';
    $titulo = 'Reporte PDF';
    include __DIR__ . '/../../includes/sidebar.php';
    ?>
    <main class="page">
      <section class="card">
        <h2 class="card-title">📄 Selecciona un cliente</h2>
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
                    <div class="dt-client-meta text-muted">Generar reporte</div>
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
// CARGAR DATOS DEL REPORTE
// ============================================================

// Dietista (para footer)
$stmt = $conn->prepare("SELECT nombre_completo, email FROM usuarios WHERE id=?");
$stmt->bind_param('i', $uid);
$stmt->execute();
$dietista = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Anamnesis
$stmt = $conn->prepare("SELECT * FROM fichas_anamnesis WHERE id_cliente=?");
$stmt->bind_param('i', $idC);
$stmt->execute();
$anam = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

// Pesos (filtrados por rango)
$stmt = $conn->prepare(
    "SELECT fecha_hora, peso_kg, porcentaje_grasa
     FROM progresos_metricas
     WHERE id_cliente=? AND fecha_hora BETWEEN ? AND ?
     ORDER BY fecha_hora ASC"
);
$stmt->bind_param('iss', $idC, $desdeIni, $hastaFin);
$stmt->execute();
$pesos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$pesoIni  = !empty($pesos) ? (float)$pesos[0]['peso_kg'] : null;
$pesoAct  = !empty($pesos) ? (float)end($pesos)['peso_kg'] : null;
$variacion = ($pesoIni !== null && $pesoAct !== null) ? ($pesoAct - $pesoIni) : 0;

// Última medida DENTRO del rango
$stmt = $conn->prepare("SELECT * FROM medidas_corporales WHERE id_cliente=? AND fecha BETWEEN ? AND ? ORDER BY fecha DESC LIMIT 1");
$stmt->bind_param('iss', $idC, $desde, $hasta);
$stmt->execute();
$ultMedida = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Check-ins semanales dentro del rango (sin límite arbitrario)
$stmt = $conn->prepare(
    "SELECT * FROM checkins_semanales
     WHERE id_cliente=? AND semana_inicio BETWEEN ? AND ?
     ORDER BY semana_inicio DESC"
);
$stmt->bind_param('iss', $idC, $desde, $hasta);
$stmt->execute();
$checkins = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Objetivos
$stmt = $conn->prepare(
    "SELECT * FROM objetivos WHERE id_cliente=?
     ORDER BY (estado='activo') DESC, fecha_creacion DESC"
);
$stmt->bind_param('i', $idC);
$stmt->execute();
$objetivos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Consultas DENTRO del rango (todas)
$stmt = $conn->prepare(
    "SELECT fecha, tipo, asistio, notas_compartidas, plan_siguiente, proxima_cita
     FROM consultas
     WHERE id_cliente=? AND id_dietista=? AND fecha BETWEEN ? AND ?
     ORDER BY fecha DESC"
);
$stmt->bind_param('iiss', $idC, $uid, $desdeIni, $hastaFin);
$stmt->execute();
$consultas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ============================================================
// SVG de la gráfica de peso (sin dependencias)
// ============================================================
function svg_reporte_peso(array $puntos): string {
    if (count($puntos) < 1) return '';
    $W = 700; $H = 220; $padL = 36; $padR = 12; $padT = 16; $padB = 28;
    $vals = array_map(function ($p) { return (float)$p['peso_kg']; }, $puntos);
    $min  = floor(min($vals) - 1);
    $max  = ceil(max($vals) + 1);
    if ($min === $max) $max = $min + 1;
    $n = count($puntos);
    $stepX = $n > 1 ? ($W - $padL - $padR) / ($n - 1) : 0;
    $sy = function ($v) use ($min, $max, $H, $padT, $padB) {
        return $padT + ($H - $padT - $padB) * (1 - (($v - $min) / ($max - $min)));
    };

    $d = '';
    foreach ($puntos as $i => $p) {
        $x = $padL + $i * $stepX;
        $y = $sy((float)$p['peso_kg']);
        $d .= ($i === 0 ? 'M ' : ' L ') . round($x, 1) . ' ' . round($y, 1);
    }

    $grid = '';
    for ($i = 0; $i <= 3; $i++) {
        $v = $min + ($max - $min) * $i / 3;
        $y = $sy($v);
        $grid .= '<line x1="' . $padL . '" y1="' . round($y, 1) . '" x2="' . ($W - $padR) . '" y2="' . round($y, 1) . '" class="g-grid"/>';
        $grid .= '<text x="4" y="' . round($y + 4, 1) . '" class="g-axis">' . round($v) . '</text>';
    }

    $dots = '';
    foreach ($puntos as $i => $p) {
        $x = $padL + $i * $stepX;
        $y = $sy((float)$p['peso_kg']);
        $dots .= '<circle cx="' . round($x, 1) . '" cy="' . round($y, 1) . '" r="3" class="g-dot"/>';
    }

    return '<svg viewBox="0 0 ' . $W . ' ' . $H . '" class="g-svg" preserveAspectRatio="none" role="img" aria-label="Evolución del peso">'
         . $grid . '<path d="' . $d . '" class="g-line"/>' . $dots . '</svg>';
}
$svgPeso = svg_reporte_peso($pesos);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reporte · <?= e($cliente['nombre_completo']) ?></title>
<link rel="stylesheet" href="../../css/style.css">
</head>
<body class="print-body">

  <!-- Toolbar superior · NO se imprime -->
  <div class="print-toolbar no-print">
    <a class="btn btn-outline" href="dietista_ficha.php?id=<?= $idC ?>">← Volver a ficha</a>
    <button type="button" class="btn btn-ghost" id="btnAbrirOpciones">⚙️ Personalizar reporte</button>
    <button type="button" class="btn btn-primary" onclick="window.print()">🖨️ Imprimir / Guardar PDF</button>
  </div>

  <!-- Panel de personalización (rango + nota del dietista) -->
  <form method="get" class="reporte-opciones no-print" id="reporteOpciones" <?= ($_GET ? 'open' : '') ?>>
    <input type="hidden" name="id_cliente" value="<?= (int)$idC ?>">
    <fieldset>
      <legend>📅 Periodo del reporte</legend>
      <div class="reporte-rango-grid">
        <label class="field">
          <span class="field-label">Desde</span>
          <input class="field-input" type="date" name="desde" value="<?= e($desde) ?>" required>
        </label>
        <label class="field">
          <span class="field-label">Hasta</span>
          <input class="field-input" type="date" name="hasta" value="<?= e($hasta) ?>" required>
        </label>
      </div>
      <div class="reporte-presets">
        <button type="button" class="btn btn-mini btn-outline" data-preset="7">Última semana</button>
        <button type="button" class="btn btn-mini btn-outline" data-preset="30">Último mes</button>
        <button type="button" class="btn btn-mini btn-outline" data-preset="90">Últimos 3 meses</button>
        <button type="button" class="btn btn-mini btn-outline" data-preset="180">Últimos 6 meses</button>
        <button type="button" class="btn btn-mini btn-outline" data-preset="365">Último año</button>
      </div>
    </fieldset>

    <fieldset>
      <legend>📝 Nota del dietista <span class="text-muted">(opcional, aparece al inicio del reporte)</span></legend>
      <label class="field">
        <textarea class="field-textarea" name="nota" rows="4" maxlength="2000"
                  placeholder="Ej: Reporte de evolución mensual. Cliente con buena adherencia, ajustamos macros para la fase 2..."><?= e($nota) ?></textarea>
      </label>
    </fieldset>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary">🔄 Actualizar reporte</button>
    </div>
  </form>

  <article class="print-doc print-doc--compact">

    <!-- Cabecera -->
    <header class="print-head">
      <h1 class="print-title">Reporte nutricional</h1>
      <p class="print-meta">
        <strong><?= e($cliente['nombre_completo']) ?></strong> · <?= e($cliente['email']) ?><br>
        Periodo: <strong><?= e(date('d/m/Y', strtotime($desde))) ?></strong> — <strong><?= e(date('d/m/Y', strtotime($hasta))) ?></strong><br>
        Generado el <?= e(date('d/m/Y H:i')) ?> por <?= e($dietista['nombre_completo']) ?>
      </p>
    </header>

    <!-- Nota del dietista (si se ha rellenado) -->
    <?php if ($nota !== ''): ?>
      <section class="print-section reporte-nota-section">
        <h2 class="print-h2">📝 Nota del dietista</h2>
        <p class="reporte-nota-text"><?= nl2br(e($nota)) ?></p>
      </section>
    <?php endif; ?>

    <!-- Anamnesis -->
    <section class="print-section">
      <h2 class="print-h2">📋 Anamnesis</h2>
      <?php if (!$anam): ?>
        <p class="text-muted">El cliente aún no ha completado su perfil.</p>
      <?php else: ?>
        <dl class="dl-stats">
          <?php
          $rows = [
            'Sexo'              => $anam['sexo'] ?? '—',
            'Nacimiento'        => !empty($anam['fecha_nacimiento']) ? date('d/m/Y', strtotime($anam['fecha_nacimiento'])) : '—',
            'Altura'            => !empty($anam['altura_cm']) ? rtrim(rtrim((string)$anam['altura_cm'], '0'), '.') . ' cm' : '—',
            'Factor actividad'  => $anam['factor_actividad'] ?? '—',
            'Pasos diarios'     => $anam['pasos_diarios'] ?? '—',
            'Días de gym'       => $anam['dias_gym'] ?? '—',
            'Min/sesión'        => $anam['min_sesion'] ?? '—',
            'Tipo entreno'      => $anam['tipo_entreno'] ?? '—',
            'Tipo trabajo'      => $anam['tipo_trabajo'] ?? '—',
          ];
          foreach ($rows as $lbl => $val): ?>
            <dt><?= e($lbl) ?></dt>
            <dd><?= e((string)$val) ?></dd>
          <?php endforeach; ?>
        </dl>
        <?php if (!empty($anam['alergias'])): ?>
          <p class="ficha-block-title">Alergias / intolerancias</p>
          <p class="ficha-block-text"><?= nl2br(e($anam['alergias'])) ?></p>
        <?php endif; ?>
      <?php endif; ?>
    </section>

    <!-- Peso -->
    <section class="print-section">
      <h2 class="print-h2">⚖️ Evolución del peso</h2>
      <?php if (empty($pesos)): ?>
        <p class="text-muted">Sin registros de peso.</p>
      <?php else: ?>
        <div class="prog-stats">
          <div class="prog-stat">
            <span class="prog-stat-num"><?= e(rtrim(rtrim((string)$pesoAct, '0'), '.')) ?> kg</span>
            <span class="prog-stat-lbl">Actual</span>
          </div>
          <div class="prog-stat">
            <span class="prog-stat-num"><?= e(rtrim(rtrim((string)$pesoIni, '0'), '.')) ?> kg</span>
            <span class="prog-stat-lbl">Inicial</span>
          </div>
          <div class="prog-stat">
            <span class="prog-stat-num <?= $variacion < 0 ? 'is-down' : ($variacion > 0 ? 'is-up' : '') ?>">
              <?= ($variacion > 0 ? '+' : '') . number_format($variacion, 1, ',', '') ?> kg
            </span>
            <span class="prog-stat-lbl">Variación</span>
          </div>
          <div class="prog-stat">
            <span class="prog-stat-num"><?= count($pesos) ?></span>
            <span class="prog-stat-lbl">Registros</span>
          </div>
        </div>
        <div class="g-wrap" style-disabled=""><?= $svgPeso ?></div>
      <?php endif; ?>
    </section>

    <!-- Última medida -->
    <section class="print-section">
      <h2 class="print-h2">📏 Última medida corporal</h2>
      <?php if (!$ultMedida): ?>
        <p class="text-muted">Sin medidas registradas.</p>
      <?php else: ?>
        <p class="text-soft">Fecha: <strong><?= e(date('d/m/Y', strtotime($ultMedida['fecha']))) ?></strong></p>
        <dl class="dl-stats">
          <?php
          $cms = ['cintura'=>'Cintura','cadera'=>'Cadera','pecho'=>'Pecho','cuello'=>'Cuello',
                  'hombros'=>'Hombros','brazo_izq'=>'Brazo izq.','brazo_der'=>'Brazo der.',
                  'muslo_izq'=>'Muslo izq.','muslo_der'=>'Muslo der.','pantorrilla'=>'Pantorrilla'];
          foreach ($cms as $k => $lbl):
            if ($ultMedida[$k] !== null): ?>
            <dt><?= e($lbl) ?></dt>
            <dd><?= e(rtrim(rtrim((string)$ultMedida[$k], '0'), '.')) ?> cm</dd>
          <?php endif; endforeach; ?>
        </dl>
      <?php endif; ?>
    </section>

    <!-- Check-ins -->
    <section class="print-section">
      <h2 class="print-h2">📝 Últimos check-ins semanales</h2>
      <?php if (!$checkins): ?>
        <p class="text-muted">Sin check-ins registrados.</p>
      <?php else: ?>
        <table class="print-table">
          <thead>
            <tr>
              <th>Semana</th>
              <th class="num">Hambre</th>
              <th class="num">Energía</th>
              <th class="num">Sueño</th>
              <th class="num">Dieta</th>
              <th class="num">Ánimo</th>
              <th class="num">Media</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($checkins as $h):
              $ini = new DateTime($h['semana_inicio']);
              $fin = (clone $ini)->modify('+6 days');
              $media = round(($h['hambre']+$h['energia']+$h['sueno']+$h['cumplimiento_dieta']+$h['animo'])/5, 1);
            ?>
              <tr>
                <td><?= e($ini->format('d/m')) ?>–<?= e($fin->format('d/m/Y')) ?></td>
                <td class="num"><?= (int)$h['hambre'] ?></td>
                <td class="num"><?= (int)$h['energia'] ?></td>
                <td class="num"><?= (int)$h['sueno'] ?></td>
                <td class="num"><?= (int)$h['cumplimiento_dieta'] ?></td>
                <td class="num"><?= (int)$h['animo'] ?></td>
                <td class="num"><strong><?= e((string)$media) ?>/5</strong></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php foreach ($checkins as $h):
          if (!empty($h['observaciones'])):
            $ini = new DateTime($h['semana_inicio']); ?>
          <p class="ficha-block-text">
            <small><strong><?= e($ini->format('d/m/Y')) ?></strong>:</small>
            “<?= nl2br(e($h['observaciones'])) ?>”
          </p>
        <?php endif; endforeach; ?>
      <?php endif; ?>
    </section>

    <!-- Objetivos -->
    <section class="print-section">
      <h2 class="print-h2">🎯 Objetivos</h2>
      <?php if (!$objetivos): ?>
        <p class="text-muted">Sin objetivos definidos.</p>
      <?php else: ?>
        <table class="print-table">
          <thead>
            <tr>
              <th>Título</th>
              <th>Tipo</th>
              <th>Inicial</th>
              <th>Objetivo</th>
              <th>Límite</th>
              <th>Estado</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($objetivos as $o): ?>
              <tr>
                <td><strong><?= e($o['titulo']) ?></strong></td>
                <td><?= e(ucfirst($o['tipo'])) ?></td>
                <td><?= $o['valor_inicial']  !== null ? e(rtrim(rtrim($o['valor_inicial'], '0'), '.')) . ' ' . e((string)$o['unidad']) : '—' ?></td>
                <td><?= $o['valor_objetivo'] !== null ? e(rtrim(rtrim($o['valor_objetivo'],'0'), '.')) . ' ' . e((string)$o['unidad']) : '—' ?></td>
                <td><?= !empty($o['fecha_limite']) ? e(date('d/m/Y', strtotime($o['fecha_limite']))) : '—' ?></td>
                <td><?= e(ucfirst($o['estado'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>

    <!-- Consultas -->
    <section class="print-section">
      <h2 class="print-h2">📅 Últimas consultas</h2>
      <?php if (!$consultas): ?>
        <p class="text-muted">Sin consultas registradas.</p>
      <?php else: foreach ($consultas as $c): ?>
        <article class="report-cons">
          <header>
            <strong><?= e(date('d/m/Y H:i', strtotime($c['fecha']))) ?></strong>
            · <?= e(ucfirst($c['tipo'])) ?>
            <?php if ((int)$c['asistio'] === 0): ?> · <em>No asistió</em><?php endif; ?>
          </header>
          <?php if (!empty($c['notas_compartidas'])): ?>
            <p class="ficha-block-title">Notas</p>
            <p class="ficha-block-text"><?= nl2br(e($c['notas_compartidas'])) ?></p>
          <?php endif; ?>
          <?php if (!empty($c['plan_siguiente'])): ?>
            <p class="ficha-block-title">Plan</p>
            <p class="ficha-block-text"><?= nl2br(e($c['plan_siguiente'])) ?></p>
          <?php endif; ?>
          <?php if (!empty($c['proxima_cita'])): ?>
            <p class="text-soft">Próxima cita sugerida: <strong><?= e(date('d/m/Y', strtotime($c['proxima_cita']))) ?></strong></p>
          <?php endif; ?>
        </article>
      <?php endforeach; endif; ?>
    </section>

    <footer class="print-foot">
      Generado por DIETISTA · <?= e($dietista['nombre_completo']) ?>
      <?= !empty($dietista['email']) ? ' · ' . e($dietista['email']) : '' ?>
    </footer>
  </article>

<script>
(function(){
  // Presets de rango (suman al evento click)
  document.querySelectorAll('#reporteOpciones [data-preset]').forEach(function(b){
    b.addEventListener('click', function(){
      var dias = parseInt(b.dataset.preset, 10) || 30;
      var hoy = new Date();
      var ant = new Date(); ant.setDate(ant.getDate() - dias);
      var fmt = function(d){
        return d.getFullYear() + '-' +
               String(d.getMonth()+1).padStart(2,'0') + '-' +
               String(d.getDate()).padStart(2,'0');
      };
      var f = document.querySelector('#reporteOpciones [name="desde"]');
      var t = document.querySelector('#reporteOpciones [name="hasta"]');
      if (f) f.value = fmt(ant);
      if (t) t.value = fmt(hoy);
    });
  });

  // Botón "Personalizar reporte" → toggle panel
  var btnAbrir = document.getElementById('btnAbrirOpciones');
  var panel    = document.getElementById('reporteOpciones');
  if (btnAbrir && panel) {
    btnAbrir.addEventListener('click', function(){
      panel.hasAttribute('open') ? panel.removeAttribute('open') : panel.setAttribute('open','');
    });
  }
})();
</script>
</body>
</html>