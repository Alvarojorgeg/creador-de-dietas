<?php
require_once __DIR__ . '/../../includes/conexion.php';
require_once __DIR__ . '/../../includes/predicciones.php';
requiere_rol('dietista', '../../');

$uid = usuario_id();
$idC = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$ok    = '';
$error = '';

// ============================================================
// HELPER: cálculo de TDEE en PHP (mismo modelo que anamnesis.js)
// ============================================================
function calcular_tdee_php(array $a, float $peso): array {
    $out = [
        'bmr'=>0,'neat_pasos'=>0,'neat_trabajo'=>0,'eat'=>0,'tef'=>0,
        'tdee_entreno'=>0,'tdee_descanso'=>0,'tdee_pond'=>0,'factor_eq'=>0,'edad'=>0,
    ];
    if ($peso <= 0 || empty($a['fecha_nacimiento']) || empty($a['altura_cm'])) return $out;

    $altura = (float)$a['altura_cm'];
    $sexo   = $a['sexo'] ?? 'Hombre';
    $pasos  = (int)($a['pasos_diarios'] ?? 7000);
    $dias   = (int)($a['dias_gym'] ?? 3);
    $minSes = (int)($a['min_sesion'] ?? 60);
    $tipoT  = $a['tipo_trabajo']  ?? 'sentado';
    $tipoE  = $a['tipo_entreno']  ?? 'mixto';

    try {
        $bd = new DateTime($a['fecha_nacimiento']);
        $edad = $bd->diff(new DateTime('today'))->y;
    } catch (Exception $e) { $edad = 0; }

    $bmr = $sexo === 'Hombre'
        ? (10*$peso + 6.25*$altura - 5*$edad + 5)
        : (10*$peso + 6.25*$altura - 5*$edad - 161);

    $neatTrabMap = ['sentado'=>200,'de_pie'=>400,'caminando'=>600,'fisico_leve'=>800,'fisico_intenso'=>1200];
    $metMap      = ['fuerza'=>5.0,'cardio'=>8.0,'mixto'=>6.5,'calistenia'=>6.0,'otro'=>5.5];

    $neatPasos = $pasos * 0.045;
    $neatTrab  = $neatTrabMap[$tipoT] ?? 200;
    $met       = $metMap[$tipoE] ?? 5.5;
    $eat       = $met * $peso * ($minSes / 60);
    $tef       = $bmr * 0.10;

    $tdeeEntreno  = $bmr + $neatPasos + $neatTrab + $eat + $tef;
    $tdeeDescanso = $bmr + $neatPasos + $neatTrab + $tef;
    $tdeePond     = ($tdeeEntreno * $dias + $tdeeDescanso * (7 - $dias)) / 7;
    $factorEq     = $bmr > 0 ? $tdeePond / $bmr : 0;

    return [
        'bmr'           => $bmr,
        'neat_pasos'    => $neatPasos,
        'neat_trabajo'  => $neatTrab,
        'eat'           => $eat,
        'tef'           => $tef,
        'tdee_entreno'  => $tdeeEntreno,
        'tdee_descanso' => $tdeeDescanso,
        'tdee_pond'     => $tdeePond,
        'factor_eq'     => $factorEq,
        'edad'          => $edad,
    ];
}

// ============================================================
// MODO LISTADO
// ============================================================
if ($idC === 0) {
    $q = trim($_GET['q'] ?? '');
    if ($q !== '') {
        $stmt = $conn->prepare(
            "SELECT id, nombre_completo, email, fecha_registro, ultima_actividad
             FROM usuarios
             WHERE rol='cliente' AND id_dietista=? AND activo=1
               AND (nombre_completo LIKE ? OR email LIKE ?)
             ORDER BY nombre_completo ASC"
        );
        $like = '%' . $q . '%';
        $stmt->bind_param('iss', $uid, $like, $like);
    } else {
        $stmt = $conn->prepare(
            "SELECT id, nombre_completo, email, fecha_registro, ultima_actividad
             FROM usuarios WHERE rol='cliente' AND id_dietista=? AND activo=1
             ORDER BY nombre_completo ASC"
        );
        $stmt->bind_param('i', $uid);
    }
    $stmt->execute();
    $listado = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $base   = '../../';
    $active = 'clientes';
    $titulo = 'Mis clientes';
    include __DIR__ . '/../../includes/sidebar.php';
    ?>
    <main class="page">
      <form class="card" method="get">
        <label class="field">
          <span class="field-label">Buscar cliente</span>
          <input class="field-input" type="search" name="q" placeholder="Nombre o email" value="<?= e($q) ?>">
        </label>
        <button type="submit" class="btn btn-primary btn-block">Buscar</button>
      </form>

      <?php if (!$listado): ?>
        <div class="card text-center">
          <p class="text-muted"><?= $q !== '' ? 'Ningún cliente coincide con la búsqueda.' : 'Aún no tienes clientes asignados.' ?></p>
        </div>
      <?php else: ?>
        <section class="card">
          <h3 class="card-title">👥 <?= count($listado) ?> cliente<?= count($listado) === 1 ? '' : 's' ?></h3>
          <ul class="dt-clients" role="list">
            <?php foreach ($listado as $c): ?>
              <li class="dt-client">
                <a class="dt-client-link" href="dietista_ficha.php?id=<?= (int)$c['id'] ?>">
                  <div class="chats-avatar"><?= e(mb_strtoupper(mb_substr($c['nombre_completo'], 0, 1, 'UTF-8'), 'UTF-8')) ?></div>
                  <div class="dt-client-info">
                    <div class="dt-client-name"><?= e($c['nombre_completo']) ?></div>
                    <div class="dt-client-meta"><span class="text-muted"><?= e($c['email']) ?></span></div>
                  </div>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>
    </main>
    <?php include __DIR__ . '/../../includes/footer.php'; ?>
    <?php exit;
}

// ============================================================
// MODO FICHA — verificar propiedad
// ============================================================
$stmt = $conn->prepare(
    "SELECT id, nombre_completo, email, fecha_registro, ultima_actividad
     FROM usuarios WHERE id=? AND id_dietista=? AND rol='cliente' AND activo=1"
);
$stmt->bind_param('ii', $idC, $uid);
$stmt->execute();
$cliente = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$cliente) { header('Location: dietista_ficha.php'); exit; }

// ============================================================
// POST: añadir peso/grasa rápido desde la ficha
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $error = 'Sesión expirada.';
    } elseif (($_POST['accion'] ?? '') === 'add_peso') {
        $peso  = (float)($_POST['peso_kg'] ?? 0);
        $grasa = ($_POST['porcentaje_grasa'] ?? '') === '' ? null : (float)$_POST['porcentaje_grasa'];
        $nota  = trim($_POST['notas_cliente'] ?? '');
        $fecha = $_POST['fecha_hora'] ?? date('Y-m-d H:i:s');
        $fecha = str_replace('T', ' ', $fecha);
        if (strlen($fecha) === 16) $fecha .= ':00';

        if ($peso < 20 || $peso > 400) {
            $error = 'El peso debe estar entre 20 y 400 kg.';
        } elseif (strtotime($fecha) === false) {
            $error = 'Fecha inválida.';
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO progresos_metricas (id_cliente, fecha_hora, peso_kg, porcentaje_grasa, notas_cliente)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('isdds', $idC, $fecha, $peso, $grasa, $nota);
            $ok = $stmt->execute() ? 'Registro de peso añadido.' : 'No se pudo guardar.';
            $stmt->close();
        }
    }
}

// ============================================================
// Cargar todos los datos
// ============================================================

// Anamnesis
$stmt = $conn->prepare("SELECT * FROM fichas_anamnesis WHERE id_cliente=?");
$stmt->bind_param('i', $idC); $stmt->execute();
$anam = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

// Pesos
$stmt = $conn->prepare(
    "SELECT id, fecha_hora, peso_kg, porcentaje_grasa
     FROM progresos_metricas WHERE id_cliente=?
     ORDER BY fecha_hora ASC"
);
$stmt->bind_param('i', $idC); $stmt->execute();
$rowsPeso = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalPesos  = count($rowsPeso);
$primerPeso  = $rowsPeso[0] ?? null;
$ultimoPeso  = $rowsPeso[$totalPesos - 1] ?? null;
$variacion   = ($primerPeso && $ultimoPeso)
    ? ((float)$ultimoPeso['peso_kg'] - (float)$primerPeso['peso_kg']) : 0;
$pesoActual = $ultimoPeso ? (float)$ultimoPeso['peso_kg'] : 0;

// Último check-in
$stmt = $conn->prepare("SELECT * FROM checkins_semanales WHERE id_cliente=? ORDER BY semana_inicio DESC LIMIT 1");
$stmt->bind_param('i', $idC); $stmt->execute();
$ckin = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Total checkins
$stmt = $conn->prepare("SELECT COUNT(*) AS n FROM checkins_semanales WHERE id_cliente=?");
$stmt->bind_param('i', $idC); $stmt->execute();
$totalCheckins = (int)($stmt->get_result()->fetch_assoc()['n'] ?? 0);
$stmt->close();

// ============================================================
// ANALÍTICA AVANZADA (Feature 3)
// ============================================================

// Últimos 8 check-ins ordenados de + reciente a - reciente
$stmt = $conn->prepare(
    "SELECT semana_inicio, hambre, energia, sueno, cumplimiento_dieta, animo, observaciones
     FROM checkins_semanales
     WHERE id_cliente=?
     ORDER BY semana_inicio DESC
     LIMIT 8"
);
$stmt->bind_param('i', $idC); $stmt->execute();
$ultimosCheckins = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/**
 * Calcula el score de bienestar (0-100) de un check-in.
 * Fórmula: (6 - hambre) + energia + sueno + cumplimiento_dieta + animo  → rango [5..25]
 * Normalizado a 0-100.
 */
function bienestar_score(array $c): int {
    $score = (6 - (int)$c['hambre']) + (int)$c['energia'] + (int)$c['sueno']
           + (int)$c['cumplimiento_dieta'] + (int)$c['animo'];
    $score = max(5, min(25, $score));
    return (int)round((($score - 5) / 20) * 100);
}

$bienestarReciente   = null;  // score de las últimas 4 semanas
$bienestarAnterior   = null;  // score de las 4 anteriores
$bienestarDelta      = null;
$bienestarNivel      = 'sin-datos'; // ok / warn / bad / sin-datos

if (count($ultimosCheckins) >= 1) {
    $recientes = array_slice($ultimosCheckins, 0, 4);
    $sumas = array_map('bienestar_score', $recientes);
    $bienestarReciente = (int)round(array_sum($sumas) / count($sumas));
    if (count($ultimosCheckins) >= 5) {
        $anteriores = array_slice($ultimosCheckins, 4, 4);
        $sumas2 = array_map('bienestar_score', $anteriores);
        $bienestarAnterior = (int)round(array_sum($sumas2) / count($sumas2));
        $bienestarDelta = $bienestarReciente - $bienestarAnterior;
    }
    if      ($bienestarReciente >= 65) $bienestarNivel = 'ok';
    elseif  ($bienestarReciente >= 40) $bienestarNivel = 'warn';
    else                               $bienestarNivel = 'bad';
}

/**
 * Tendencia para una métrica del check-in, comparando promedio últimas 3 vs 3 anteriores.
 * Devuelve ['actual'=>float, 'anterior'=>float, 'delta'=>float, 'dir'=>'up'|'down'|'flat']
 * o null si no hay suficientes datos.
 */
function tendencia_metrica(array $checkins, string $campo): ?array {
    if (count($checkins) < 2) return null;
    $half = (int)floor(count($checkins) / 2);
    if ($half < 1) return null;
    $rec  = array_slice($checkins, 0, $half);
    $ant  = array_slice($checkins, $half, $half);
    $valsR = array_column($rec, $campo);
    $valsA = array_column($ant, $campo);
    if (empty($valsR) || empty($valsA)) return null;
    $aR = array_sum($valsR) / count($valsR);
    $aA = array_sum($valsA) / count($valsA);
    $delta = $aR - $aA;
    $dir = abs($delta) < 0.15 ? 'flat' : ($delta > 0 ? 'up' : 'down');
    return ['actual' => $aR, 'anterior' => $aA, 'delta' => $delta, 'dir' => $dir];
}

$tendencias = [];
if (count($ultimosCheckins) >= 4) {
    foreach (['hambre','energia','sueno','cumplimiento_dieta','animo'] as $campo) {
        $tendencias[$campo] = tendencia_metrica($ultimosCheckins, $campo);
    }
}

// --- PÉRDIDA TEÓRICA VS REAL (últimos 30 días) ---
$predFicha = predecir_cliente($conn, $idC);
$comparativa = null;
if ($predFicha['ok'] && $predFicha['kg_dia'] !== null && $pesoActual > 0) {
    // Peso de hace ~30 días (el más cercano a esa fecha en los últimos 35 días)
    $stmt = $conn->prepare(
        "SELECT peso_kg, fecha_hora
         FROM progresos_metricas
         WHERE id_cliente = ? AND peso_kg IS NOT NULL AND peso_kg > 0
           AND fecha_hora <= DATE_SUB(NOW(), INTERVAL 25 DAY)
           AND fecha_hora >= DATE_SUB(NOW(), INTERVAL 40 DAY)
         ORDER BY ABS(TIMESTAMPDIFF(DAY, fecha_hora, DATE_SUB(NOW(), INTERVAL 30 DAY))) ASC
         LIMIT 1"
    );
    $stmt->bind_param('i', $idC); $stmt->execute();
    $pesoAntes = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($pesoAntes && !empty($pesoAntes['peso_kg'])) {
        $diasReales = (int)round((time() - strtotime($pesoAntes['fecha_hora'])) / 86400);
        if ($diasReales >= 7) {  // mínimo 1 semana para que tenga sentido
            $real     = (float)$pesoAntes['peso_kg'] - $pesoActual;     // positivo = ha perdido
            $teorica  = (float)$predFicha['kg_dia'] * $diasReales;      // positivo = debería perder
            $adherencia = ($teorica != 0) ? ($real / $teorica) * 100 : null;
            // Interpretación
            $interp = '';
            $interpCls = 'neutral';
            if ($teorica > 0.1 && $real > 0.1) {
                // Esperaba perder y perdió
                if ($adherencia >= 80 && $adherencia <= 120) {
                    $interp = '✅ Pérdida real coincide con la teórica · buena adherencia.';
                    $interpCls = 'ok';
                } elseif ($adherencia < 80) {
                    $interp = '⚠️ Pierde MENOS de lo previsto · puede ser adherencia oculta, retención o estancamiento metabólico.';
                    $interpCls = 'warn';
                } else {
                    $interp = '🚀 Pierde MÁS de lo previsto · revisa si el déficit no es excesivo.';
                    $interpCls = 'warn';
                }
            } elseif ($teorica > 0.1 && $real <= 0) {
                $interp = '🔴 Esperabas pérdida y NO la hay · posible mala adherencia, retención de líquidos, o falta de actualización del peso.';
                $interpCls = 'bad';
            } elseif ($teorica <= 0 && $real > 0.1) {
                $interp = '👏 El cliente baja sin déficit teórico · NEAT/actividad real superior a la estimada.';
                $interpCls = 'ok';
            } else {
                $interp = 'ℹ️ Cliente en mantenimiento o ganando peso (esperado).';
                $interpCls = 'neutral';
            }

            $comparativa = [
                'dias'        => $diasReales,
                'peso_antes'  => (float)$pesoAntes['peso_kg'],
                'peso_ahora'  => $pesoActual,
                'real'        => $real,
                'teorica'     => $teorica,
                'diff'        => $real - $teorica,
                'adherencia'  => $adherencia,
                'interp'      => $interp,
                'interp_cls'  => $interpCls,
            ];
        }
    }
}

// Helper para flechas y colores de tendencia
function tend_arrow(array $tend, bool $invertir = false): string {
    // Si invertir=true → SUBIR es MALO (caso del hambre)
    $dir = $tend['dir'];
    if ($dir === 'flat') return '→';
    if ($invertir) return $dir === 'up' ? '↑' : '↓';
    return $dir === 'up' ? '↑' : '↓';
}
function tend_color(array $tend, bool $invertir = false): string {
    $dir = $tend['dir'];
    if ($dir === 'flat') return 'flat';
    $bueno = $invertir ? ($dir === 'down') : ($dir === 'up');
    return $bueno ? 'good' : 'bad';
}
function fmt_kg_ficha(?float $v, int $dec = 2): string {
    if ($v === null) return '—';
    $abs = abs($v);
    if ($abs < 0.005) return '0 kg';
    $signo = $v < 0 ? '+' : '−';
    return $signo . number_format($abs, $dec, ',', '.') . ' kg';
}

// Última medida
$stmt = $conn->prepare("SELECT * FROM medidas_corporales WHERE id_cliente=? ORDER BY fecha DESC LIMIT 1");
$stmt->bind_param('i', $idC); $stmt->execute();
$medida = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Próxima consulta
$stmt = $conn->prepare(
    "SELECT fecha, tipo, duracion_min FROM consultas
     WHERE id_cliente=? AND id_dietista=? AND fecha >= NOW()
     ORDER BY fecha ASC LIMIT 1"
);
$stmt->bind_param('ii', $idC, $uid); $stmt->execute();
$proxC = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Objetivos activos
$stmt = $conn->prepare(
    "SELECT id, titulo, tipo, valor_inicial, valor_objetivo, unidad, fecha_limite
     FROM objetivos WHERE id_cliente=? AND estado='activo' ORDER BY fecha_creacion DESC"
);
$stmt->bind_param('i', $idC); $stmt->execute();
$objs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Historial estrategias
$stmt = $conn->prepare(
    "SELECT id, fecha, nombre, kcal, factor_p, factor_g, gramos_p, gramos_c, gramos_g
     FROM historial_estrategias WHERE id_cliente=? ORDER BY fecha DESC LIMIT 50"
);
$stmt->bind_param('i', $idC); $stmt->execute();
$historialEstr = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Dietas asignadas en los últimos 30 días (para "qué dietas come")
$stmt = $conn->prepare(
    "SELECT d.nombre, d.icono, d.color, d.kcal_objetivo, COUNT(*) AS dias
     FROM calendario_asignaciones c
     JOIN dietas_base d ON d.id = c.id_dieta
     WHERE c.id_cliente=? AND c.fecha_asignada >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
     GROUP BY d.id ORDER BY dias DESC LIMIT 5"
);
$stmt->bind_param('i', $idC); $stmt->execute();
$dietas30 = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// TDEE
$tdee = calcular_tdee_php($anam, $pesoActual);
$tieneAnamnesis  = !empty($anam) && !empty($anam['fecha_nacimiento']);
$puedeEstrategia = $tieneAnamnesis && $pesoActual > 0;

// Valores actuales de estrategia
$cur_factor_p = isset($anam['factor_p']) ? (float)$anam['factor_p'] : 2.0;
$cur_factor_g = isset($anam['factor_g']) ? (float)$anam['factor_g'] : 0.9;
$cur_kcal     = isset($anam['obj_kcal']) && (int)$anam['obj_kcal'] > 0
    ? (int)$anam['obj_kcal']
    : (int)round($tdee['tdee_pond']);

// ============================================================
// ESTADÍSTICAS CALCULADAS
// ============================================================

// Días con registro de peso (info)
$diasSeguimiento = 0;
if ($totalPesos >= 2) {
    $d1 = new DateTime($primerPeso['fecha_hora']);
    $d2 = new DateTime($ultimoPeso['fecha_hora']);
    $diasSeguimiento = $d1->diff($d2)->days;
}

// =================================================================
// PROYECCIÓN kg/mes y kg/semana basada en dietas asignadas + TDEE
// =================================================================
// Fórmula:
//   Para cada día con dieta asignada:
//     balance_día = kcal_dieta − TDEE_ponderado
//   total_balance = Σ balance_día  (a lo largo del periodo)
//   kg_periodo    = total_balance / 7700   (1 kg de grasa = 7.700 kcal)
//
// Cuando hay varias dietas distintas asignadas en días diferentes,
// cada día contribuye con SU PROPIA dieta. Esto equivale a una media
// ponderada por número de días de cada dieta.
// Si un mismo día tiene varias dietas, solo se cuenta UNA.
// Los días SIN dieta NO se cuentan (asumimos mantenimiento).
//
$kgProySemana   = null;
$kgProyMes      = null;
$diasAsignSem   = 0;
$diasAsignMes   = 0;
$kcalMediaSem   = 0;
$kcalMediaMes   = 0;
$balancePromSem = 0;
$balancePromMes = 0;
$desgloseDietas = [];
$tdeePond       = $tdee['tdee_pond'] ?? 0;
$mesFin         = null;

if ($tdeePond > 0) {
    $hoyDt   = new DateTime('today');
    $diaDow  = (int)$hoyDt->format('N');                // 1=lunes
    $lunes   = (clone $hoyDt)->modify('-' . ($diaDow - 1) . ' days');
    $domingo = (clone $lunes)->modify('+6 days');
    $mesIni  = (clone $hoyDt)->modify('first day of this month');
    $mesFin  = (clone $hoyDt)->modify('last day of this month');

    // Rango de consulta: cubrir mes Y semana (incluso si cruza meses)
    $queryStart = $lunes   < $mesIni ? $lunes   : $mesIni;
    $queryEnd   = $domingo > $mesFin ? $domingo : $mesFin;

    $stmt = $conn->prepare(
        "SELECT c.fecha_asignada, d.id AS id_dieta, d.nombre AS dieta_nombre,
                d.color AS dieta_color, d.kcal_objetivo
         FROM calendario_asignaciones c
         JOIN dietas_base d ON d.id = c.id_dieta
         WHERE c.id_cliente=? AND c.fecha_asignada BETWEEN ? AND ?
         ORDER BY c.fecha_asignada ASC, c.id ASC"
    );
    $f1 = $queryStart->format('Y-m-d');
    $f2 = $queryEnd->format('Y-m-d');
    $stmt->bind_param('iss', $idC, $f1, $f2);
    $stmt->execute();
    $rowsAsign = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Primera dieta por fecha (un día = una dieta efectiva)
    $primeraPorFecha = [];
    foreach ($rowsAsign as $r) {
        $fk = $r['fecha_asignada'];
        if (!isset($primeraPorFecha[$fk])) $primeraPorFecha[$fk] = $r;
    }

    $semIni = $lunes->format('Y-m-d');
    $semFin = $domingo->format('Y-m-d');
    $mIni   = $mesIni->format('Y-m-d');
    $mFin   = $mesFin->format('Y-m-d');

    $balMes = 0; $balSem = 0;
    $kcalSumMes = 0; $kcalSumSem = 0;
    $aggDietas = [];

    foreach ($primeraPorFecha as $fecha => $r) {
        $kcal = (int)$r['kcal_objetivo'];
        $bal  = $kcal - $tdeePond;
        $idD  = (int)$r['id_dieta'];

        $enMes = ($fecha >= $mIni && $fecha <= $mFin);
        $enSem = ($fecha >= $semIni && $fecha <= $semFin);

        if ($enMes) {
            $balMes += $bal; $diasAsignMes++; $kcalSumMes += $kcal;
            if (!isset($aggDietas[$idD])) {
                $aggDietas[$idD] = [
                    'nombre'        => $r['dieta_nombre'],
                    'color'         => $r['dieta_color'] ?: '#2F9E73',
                    'dias'          => 0,
                    'kcal_total'    => 0,
                    'balance_total' => 0,
                ];
            }
            $aggDietas[$idD]['dias']++;
            $aggDietas[$idD]['kcal_total']    += $kcal;
            $aggDietas[$idD]['balance_total'] += $bal;
        }
        if ($enSem) {
            $balSem += $bal; $diasAsignSem++; $kcalSumSem += $kcal;
        }
    }

    $kgProyMes      = $balMes / 7700;
    $kgProySemana   = $balSem / 7700;
    $kcalMediaSem   = $diasAsignSem > 0 ? round($kcalSumSem / $diasAsignSem) : 0;
    $kcalMediaMes   = $diasAsignMes > 0 ? round($kcalSumMes / $diasAsignMes) : 0;
    $balancePromSem = $diasAsignSem > 0 ? round($balSem / $diasAsignSem) : 0;
    $balancePromMes = $diasAsignMes > 0 ? round($balMes / $diasAsignMes) : 0;

    foreach ($aggDietas as $idD => $a) {
        $desgloseDietas[] = [
            'nombre'      => $a['nombre'],
            'color'       => $a['color'],
            'dias'        => $a['dias'],
            'kcal_media'  => round($a['kcal_total'] / $a['dias']),
            'balance_dia' => round($a['balance_total'] / $a['dias']),
            'kg_contrib'  => round($a['balance_total'] / 7700, 2),
        ];
    }
    usort($desgloseDietas, function($a, $b) { return $b['dias'] <=> $a['dias']; });
}

// Adherencia check-in
$diasComoCliente    = max(7, (new DateTime($cliente['fecha_registro']))->diff(new DateTime('today'))->days);
$semanasComoCliente = max(1, (int)ceil($diasComoCliente / 7));
$pctAdherencia      = $semanasComoCliente > 0 ? min(100, round(($totalCheckins / $semanasComoCliente) * 100)) : 0;

// IMC
$imc = null;
if (!empty($anam['altura_cm']) && $pesoActual > 0) {
    $altM = (float)$anam['altura_cm'] / 100;
    if ($altM > 0) $imc = $pesoActual / ($altM * $altM);
}
function clasificar_imc(float $i): array {
    if ($i < 18.5) return ['Bajo peso',     '#3A86C7'];
    if ($i < 25)   return ['Normopeso',     '#2F9E73'];
    if ($i < 30)   return ['Sobrepeso',     '#F2A03D'];
    if ($i < 35)   return ['Obesidad I',    '#D24A4A'];
    if ($i < 40)   return ['Obesidad II',   '#B23B3B'];
    return                ['Obesidad III',  '#8A2424'];
}
$imcClas = $imc !== null ? clasificar_imc($imc) : ['—', '#8A9690'];

// Edad
$edad = $tdee['edad'] ?? 0;


$base   = '../../';
$active = 'clientes';
$titulo = $cliente['nombre_completo'];
include __DIR__ . '/../../includes/sidebar.php';
?>
<main class="page">

  <?php if ($ok):    ?><div class="alert alert-success" role="status"><?= e($ok) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"  role="alert"><?= e($error) ?></div><?php endif; ?>

  <!-- Identidad -->
  <article class="card ficha-id">
    <div class="chats-avatar ficha-avatar">
      <?= e(mb_strtoupper(mb_substr($cliente['nombre_completo'], 0, 1, 'UTF-8'), 'UTF-8')) ?>
    </div>
    <div class="ficha-id-info">
      <h2 class="ficha-name"><?= e($cliente['nombre_completo']) ?></h2>
      <div class="ficha-email"><?= e($cliente['email']) ?></div>
      <div class="text-muted ficha-meta">
        Cliente desde <?= e(date('d/m/Y', strtotime($cliente['fecha_registro']))) ?>
        <?php if (!empty($cliente['ultima_actividad'])): ?>
          · Última conexión <?= e(date('d/m/Y', strtotime($cliente['ultima_actividad']))) ?>
        <?php endif; ?>
      </div>
    </div>
    <a class="btn btn-outline btn-mini" href="dietista_ficha.php">← Volver</a>
  </article>

  <!-- Accesos rápidos -->
  <nav class="ficha-quick" aria-label="Acciones">
    <a class="dash-quick-btn" href="../../chats.php?con=<?= $idC ?>"><span>💬</span><span>Chat</span></a>
    <a class="dash-quick-btn" href="dietista_dietas.php?id_cliente=<?= $idC ?>"><span>🍽️</span><span>Dietas</span></a>
    <a class="dash-quick-btn" href="dietista_medidas.php?id_cliente=<?= $idC ?>"><span>📏</span><span>Medidas</span></a>
    <a class="dash-quick-btn" href="dietista_checkin.php?id_cliente=<?= $idC ?>"><span>📝</span><span>Check-ins</span></a>
    <a class="dash-quick-btn" href="dietista_objetivos.php?id_cliente=<?= $idC ?>"><span>🎯</span><span>Objetivos</span></a>
    <a class="dash-quick-btn" href="dietista_comparador_fotos.php?id_cliente=<?= $idC ?>"><span>📸</span><span>Fotos</span></a>
    <a class="dash-quick-btn" href="dietista_calendario.php?id_cliente=<?= $idC ?>"><span>📆</span><span>Consultas</span></a>
    <a class="dash-quick-btn" href="dietista_reporte_pdf.php?id_cliente=<?= $idC ?>"><span>📄</span><span>Reporte</span></a>
  </nav>

  <!-- Peso + estadísticas + TDEE + gráfica -->
  <article class="card">
    <h3 class="card-title">⚖️ Peso y composición</h3>

    <?php if ($totalPesos === 0): ?>
      <p class="text-muted">Aún no hay registros de peso para este cliente.</p>
    <?php else: ?>
      <div class="prog-stats">
        <div class="prog-stat">
          <span class="prog-stat-num"><?= e(rtrim(rtrim((string)$ultimoPeso['peso_kg'], '0'), '.')) ?> kg</span>
          <span class="prog-stat-lbl">Actual</span>
        </div>
        <div class="prog-stat">
          <span class="prog-stat-num"><?= e(rtrim(rtrim((string)$primerPeso['peso_kg'], '0'), '.')) ?> kg</span>
          <span class="prog-stat-lbl">Inicial</span>
        </div>
        <div class="prog-stat">
          <span class="prog-stat-num <?= $variacion < 0 ? 'is-down' : ($variacion > 0 ? 'is-up' : '') ?>">
            <?= ($variacion > 0 ? '+' : '') . number_format($variacion, 1, ',', '') ?> kg
          </span>
          <span class="prog-stat-lbl">Variación</span>
        </div>
        <div class="prog-stat">
          <span class="prog-stat-num"><?= (int)$totalPesos ?></span>
          <span class="prog-stat-lbl">Registros</span>
        </div>
      </div>

      <!-- Predicción basada en dietas asignadas + TDEE -->
      <div class="prog-stats ficha-stats-extra">
        <div class="prog-stat">
          <span class="prog-stat-num <?= $kgProySemana !== null && $kgProySemana < 0 ? 'is-down' : ($kgProySemana !== null && $kgProySemana > 0 ? 'is-up' : '') ?>">
            <?php if ($kgProySemana !== null && $diasAsignSem > 0):
              echo ($kgProySemana > 0 ? '+' : '') . number_format($kgProySemana, 2, ',', '');
            else: echo '—'; endif; ?>
          </span>
          <span class="prog-stat-lbl">kg / semana<?= $diasAsignSem > 0 ? ' <span class="text-muted">(' . $diasAsignSem . 'd)</span>' : '' ?></span>
        </div>
        <div class="prog-stat">
          <span class="prog-stat-num <?= $kgProyMes !== null && $kgProyMes < 0 ? 'is-down' : ($kgProyMes !== null && $kgProyMes > 0 ? 'is-up' : '') ?>">
            <?php if ($kgProyMes !== null && $diasAsignMes > 0):
              echo ($kgProyMes > 0 ? '+' : '') . number_format($kgProyMes, 2, ',', '');
            else: echo '—'; endif; ?>
          </span>
          <span class="prog-stat-lbl">kg / mes<?= $diasAsignMes > 0 ? ' <span class="text-muted">(' . $diasAsignMes . 'd)</span>' : '' ?></span>
        </div>
        <div class="prog-stat">
          <span class="prog-stat-num" style="color: <?= e($imcClas[1]) ?>;">
            <?= $imc !== null ? number_format($imc, 1, ',', '') : '—' ?>
          </span>
          <span class="prog-stat-lbl">IMC · <?= e($imcClas[0]) ?></span>
        </div>
        <div class="prog-stat">
          <span class="prog-stat-num <?= $pctAdherencia >= 75 ? 'is-down' : ($pctAdherencia < 50 ? 'is-up' : '') ?>">
            <?= $pctAdherencia ?>%
          </span>
          <span class="prog-stat-lbl">Adherencia check-in</span>
        </div>
      </div>
      <details class="ficha-pred-detalle">
        <summary class="ficha-pred-summary">
          <span>📊 Ver desglose de la proyección</span>
        </summary>
        <div class="ficha-pred-detalle-body">

          <p class="text-muted ficha-stats-hint">
            💡 Fórmula: <strong>(kcal dieta − TDEE)</strong> sumado por cada día asignado, dividido entre <strong>7.700 kcal/kg</strong>. Si hay varias dietas, cada día contribuye con la suya (media ponderada automática).
          </p>

          <div class="ficha-pred-table">
            <div class="ficha-pred-row">
              <span>TDEE ponderado del cliente</span>
              <strong><?= number_format($tdeePond, 0, ',', '.') ?> kcal/día</strong>
            </div>
            <div class="ficha-pred-row">
              <span>Días con dieta este mes</span>
              <strong><?= (int)$diasAsignMes ?> de <?= $mesFin ? (int)$mesFin->format('j') : 0 ?></strong>
            </div>
            <div class="ficha-pred-row">
              <span>Media kcal/día (mes)</span>
              <strong><?= number_format($kcalMediaMes, 0, ',', '.') ?> kcal</strong>
            </div>
            <div class="ficha-pred-row">
              <span>Balance medio/día (mes)</span>
              <strong class="<?= $balancePromMes < 0 ? 'is-down' : ($balancePromMes > 0 ? 'is-up' : '') ?>">
                <?= ($balancePromMes > 0 ? '+' : '') . number_format($balancePromMes, 0, ',', '.') ?> kcal
              </strong>
            </div>
          </div>

          <?php if (!empty($desgloseDietas)): ?>
            <p class="ficha-pred-subtitle">🍽️ Por dieta (este mes)</p>
            <ul class="ficha-pred-dietas" role="list">
              <?php foreach ($desgloseDietas as $dd): ?>
                <li class="ficha-pred-dieta" style="border-left:4px solid <?= e($dd['color']) ?>;">
                  <div class="ficha-pred-dieta-head">
                    <strong><?= e($dd['nombre']) ?></strong>
                    <span class="text-muted"><?= (int)$dd['dias'] ?> día<?= $dd['dias']==1?'':'s' ?></span>
                  </div>
                  <div class="ficha-pred-dieta-vals text-soft">
                    <span><?= number_format($dd['kcal_media'], 0, ',', '.') ?> kcal/día</span>
                    <span>·</span>
                    <span class="<?= $dd['balance_dia'] < 0 ? 'is-down' : 'is-up' ?>">
                      <?= ($dd['balance_dia'] > 0 ? '+' : '') . number_format($dd['balance_dia'], 0, ',', '.') ?> kcal/día
                    </span>
                    <span>·</span>
                    <span class="<?= $dd['kg_contrib'] < 0 ? 'is-down' : 'is-up' ?>">
                      contribuye <?= ($dd['kg_contrib'] > 0 ? '+' : '') . number_format($dd['kg_contrib'], 2, ',', '') ?> kg
                    </span>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>

        </div>
      </details>

      <button type="button" class="btn btn-outline btn-block ficha-perfil-btn" id="btnPerfilCompleto">
        🔍 Ver perfil completo del paciente
      </button>

      <!-- Gráfica de pesos -->
      <div class="ficha-peso-chart-wrap">
        <canvas id="pesoChart" aria-label="Evolución del peso"></canvas>
      </div>
      <?php if ($totalPesos >= 2): ?>
        <button type="button" class="btn btn-outline btn-block ficha-peso-chart-btn" id="btnPesoVerMas">📈 Ver gráfica detallada</button>
      <?php endif; ?>
    <?php endif; ?>

    <!-- Bloque TDEE -->
    <?php if ($puedeEstrategia): ?>
      <div class="ficha-tdee-block">
        <h4 class="ficha-block-title">⚡ Gasto calórico estimado <span class="text-muted">(último peso)</span></h4>
        <div class="tdee-days">
          <div class="tdee-day tdee-day--entreno">
            <span class="tdee-day-lbl">Día entreno</span>
            <span class="tdee-day-num"><?= number_format($tdee['tdee_entreno'], 0, ',', '.') ?> kcal</span>
          </div>
          <div class="tdee-day tdee-day--descanso">
            <span class="tdee-day-lbl">Día descanso</span>
            <span class="tdee-day-num"><?= number_format($tdee['tdee_descanso'], 0, ',', '.') ?> kcal</span>
          </div>
          <div class="tdee-day tdee-day--tmb">
            <span class="tdee-day-lbl">TMB / BMR</span>
            <span class="tdee-day-num"><?= number_format($tdee['bmr'], 0, ',', '.') ?> kcal</span>
          </div>
        </div>
        <div class="ficha-tdee-detail">
          <span>TDEE ponderado: <strong><?= number_format($tdee['tdee_pond'], 0, ',', '.') ?> kcal</strong></span>
          <span class="text-muted">· Factor ×<?= number_format($tdee['factor_eq'], 2, ',', '') ?></span>
        </div>
      </div>
    <?php elseif ($totalPesos > 0): ?>
      <p class="text-muted ficha-tdee-warn">El cliente aún no ha completado su anamnesis.</p>
    <?php endif; ?>

    <!-- Form rápido -->
    <form method="post" class="ficha-quick-form">
      <h4 class="ficha-quick-form-title">
        <?= $totalPesos === 0 ? '📝 Establecer peso inicial' : '➕ Añadir registro de peso' ?>
      </h4>
      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="accion" value="add_peso">

      <label class="field">
        <span class="field-label">Fecha y hora</span>
        <input class="field-input" type="datetime-local" name="fecha_hora" value="<?= e(date('Y-m-d\TH:i')) ?>" required>
      </label>

      <div class="grid-2">
        <label class="field">
          <span class="field-label">Peso (kg)</span>
          <input class="field-input" type="number" step="0.1" min="20" max="400" name="peso_kg" inputmode="decimal" required>
        </label>
        <label class="field">
          <span class="field-label">% grasa <span class="text-muted">(opcional)</span></span>
          <input class="field-input" type="number" step="0.1" min="0" max="80" name="porcentaje_grasa" inputmode="decimal">
        </label>
      </div>

      <label class="field">
        <span class="field-label">Nota <span class="text-muted">(opcional)</span></span>
        <input class="field-input" type="text" name="notas_cliente" maxlength="500">
      </label>

      <button type="submit" class="btn btn-primary btn-block">Guardar</button>
    </form>
  </article>

  <!-- ESTRATEGIA NUTRICIONAL (con botón guardar manual) -->
  <article class="card ficha-estr-card">
    <header class="dash-card-header">
      <h3 class="card-title">⚡ Estrategia nutricional</h3>
      <span class="ficha-estr-status" id="estrStatus" data-state="idle">●</span>
    </header>

    <?php if (!$puedeEstrategia): ?>
      <p class="text-muted">Necesitas que el cliente complete su anamnesis y tenga al menos un peso registrado para crear su estrategia.</p>
    <?php else: ?>

      <p class="text-soft ficha-estr-intro">
        Ajusta proteína y grasa por kilo de peso. Los carbohidratos se calculan con las kcal restantes.
        <br><span class="text-muted">Peso de referencia: <strong><?= e(rtrim(rtrim((string)$pesoActual, '0'), '.')) ?> kg</strong></span>
      </p>

      <div class="ficha-estr-form">
        <label class="field">
          <span class="field-label">
            Nombre <span class="text-muted">(opcional · ej: "Definición v2", "Volumen invierno"…)</span>
          </span>
          <input class="field-input" type="text" id="estr_nombre" maxlength="80" placeholder="Sin nombre">
        </label>

        <label class="field">
          <span class="field-label">
            Kcal objetivo
            <span class="ficha-estr-hint">TDEE: <?= number_format($tdee['tdee_pond'], 0, ',', '.') ?> kcal</span>
          </span>
          <input class="field-input" type="number" id="estr_kcal" min="800" max="6000" step="1" value="<?= $cur_kcal ?>" inputmode="numeric">
        </label>

        <div class="grid-2">
          <label class="field">
            <span class="field-label">Proteína: <strong id="estr_lbl_p"><?= number_format($cur_factor_p, 2, ',', '') ?></strong> g/kg</span>
            <input type="range" class="slider" id="estr_factor_p" min="0.8" max="3.5" step="0.05" value="<?= $cur_factor_p ?>">
            <span class="field-help">Recomendado: 1,6 – 2,4 g/kg</span>
          </label>
          <label class="field">
            <span class="field-label">Grasa: <strong id="estr_lbl_g"><?= number_format($cur_factor_g, 2, ',', '') ?></strong> g/kg</span>
            <input type="range" class="slider" id="estr_factor_g" min="0.3" max="2.0" step="0.05" value="<?= $cur_factor_g ?>">
            <span class="field-help">Recomendado: 0,8 – 1,2 g/kg</span>
          </label>
        </div>
      </div>

      <!-- Macros calculados (vista previa en tiempo real) -->
      <div class="tdee-macros ficha-estr-macros">
        <div class="tdee-macro tdee-macro--p">
          <span class="tdee-macro-name">Proteínas</span>
          <span class="tdee-macro-g" id="estr_g_p">— g</span>
          <span class="tdee-macro-k" id="estr_k_p">— kcal</span>
        </div>
        <div class="tdee-macro tdee-macro--c">
          <span class="tdee-macro-name">Carbohidratos</span>
          <span class="tdee-macro-g" id="estr_g_c">— g</span>
          <span class="tdee-macro-k" id="estr_k_c">— kcal</span>
        </div>
        <div class="tdee-macro tdee-macro--g">
          <span class="tdee-macro-name">Grasas</span>
          <span class="tdee-macro-g" id="estr_g_g">— g</span>
          <span class="tdee-macro-k" id="estr_k_g">— kcal</span>
        </div>
      </div>

      <p class="ficha-estr-aviso" id="estrAviso" hidden></p>

      <!-- Botón guardar manual -->
      <div class="ficha-estr-save-row">
        <button type="button" class="btn btn-primary btn-block" id="estrBtnGuardar" data-state="saved">
          ✓ Estrategia actual guardada
        </button>
      </div>

      <!-- Historial -->
      <details class="ficha-estr-hist">
        <summary>
          <span>📜 Historial de estrategias</span>
          <span class="ficha-estr-hist-badge" id="estrHistCount"><?= count($historialEstr) ?></span>
        </summary>
        <ul class="ficha-estr-hist-list" id="estrHistList" role="list">
          <?php if (!$historialEstr): ?>
            <li class="ficha-estr-hist-empty">Aún no hay estrategias guardadas. Modifica los valores y pulsa "Guardar".</li>
          <?php else: foreach ($historialEstr as $h): ?>
            <li class="ficha-estr-hist-item" data-id="<?= (int)$h['id'] ?>">
              <div class="ficha-estr-hist-info">
                <div class="ficha-estr-hist-fecha">
                  <?php if (!empty($h['nombre'])): ?>
                    <strong class="ficha-estr-hist-nombre"><?= e($h['nombre']) ?></strong> ·
                  <?php endif; ?>
                  <?= e(date('d/m/Y H:i', strtotime($h['fecha']))) ?>
                </div>
                <div class="ficha-estr-hist-vals">
                  <span><?= (int)$h['kcal'] ?> kcal</span> ·
                  <span>P <?= number_format($h['factor_p'], 2, ',', '') ?>g/kg (<?= (int)$h['gramos_p'] ?>g)</span> ·
                  <span>G <?= number_format($h['factor_g'], 2, ',', '') ?>g/kg (<?= (int)$h['gramos_g'] ?>g)</span> ·
                  <span>C <?= (int)$h['gramos_c'] ?>g</span>
                </div>
              </div>
              <div class="ficha-estr-hist-actions">
                <button type="button" class="btn btn-outline btn-mini" data-act="reusar"
                  data-fp="<?= e($h['factor_p']) ?>" data-fg="<?= e($h['factor_g']) ?>"
                  data-k="<?= (int)$h['kcal'] ?>" data-nombre="<?= e($h['nombre'] ?? '') ?>"
                  title="Cargar estos valores en el formulario">↺ Reusar</button>
                <button type="button" class="btn btn-ghost btn-mini" data-act="borrar" aria-label="Borrar entrada">🗑️</button>
              </div>
            </li>
          <?php endforeach; endif; ?>
        </ul>
      </details>

    <?php endif; ?>
  </article>

  <!-- Anamnesis resumen -->
  <article class="card">
    <h3 class="card-title">📋 Anamnesis</h3>
    <?php if (!$anam): ?>
      <p class="text-muted">El cliente aún no ha completado su perfil.</p>
    <?php else: ?>
      <dl class="dl-stats">
        <?php
        $rows = [
          'Sexo'              => $anam['sexo'] ?? '—',
          'Edad'              => $edad > 0 ? $edad . ' años' : '—',
          'Altura'            => !empty($anam['altura_cm']) ? rtrim(rtrim((string)$anam['altura_cm'], '0'), '.') . ' cm' : '—',
          'Pasos diarios'     => $anam['pasos_diarios'] ?? '—',
          'Días de gym'       => $anam['dias_gym'] ?? '—',
          'Tipo entreno'      => $anam['tipo_entreno'] ?? '—',
        ];
        foreach ($rows as $lbl => $val): ?>
          <dt><?= e($lbl) ?></dt>
          <dd><?= e((string)$val) ?></dd>
        <?php endforeach; ?>
      </dl>
    <?php endif; ?>
  </article>

  <!-- Último check-in -->
  <article class="card">
    <h3 class="card-title">📝 Último check-in semanal</h3>
    <?php if (!$ckin): ?>
      <p class="text-muted">El cliente aún no ha hecho ningún check-in.</p>
    <?php else: ?>
      <p class="text-soft">Semana del <strong><?= e(date('d/m/Y', strtotime($ckin['semana_inicio']))) ?></strong></p>
      <ul class="dash-checkin-resumen" role="list">
        <li><span>Hambre</span><strong><?= (int)$ckin['hambre'] ?>/5</strong></li>
        <li><span>Energía</span><strong><?= (int)$ckin['energia'] ?>/5</strong></li>
        <li><span>Sueño</span><strong><?= (int)$ckin['sueno'] ?>/5</strong></li>
        <li><span>Dieta</span><strong><?= (int)$ckin['cumplimiento_dieta'] ?>/5</strong></li>
        <li><span>Ánimo</span><strong><?= (int)$ckin['animo'] ?>/5</strong></li>
      </ul>
      <?php if (!empty($ckin['observaciones'])): ?>
        <p class="ficha-block-title">Observaciones</p>
        <p class="ficha-block-text">"<?= nl2br(e($ckin['observaciones'])) ?>"</p>
      <?php endif; ?>
    <?php endif; ?>
  </article>

  <!-- ============================================================
       ANALÍTICA AVANZADA (Feature 3)
       ============================================================ -->
  <article class="card analitica-card">
    <header class="dash-card-header">
      <h3 class="card-title">📊 Analítica avanzada</h3>
      <span class="text-muted" style="font-size: var(--fs-xs);">Últimas 4-8 semanas</span>
    </header>

    <!-- BLOQUE 1: Índice de bienestar / riesgo de abandono -->
    <section class="ana-section">
      <h4 class="ana-section-title">
        <span>💚 Índice de bienestar</span>
        <span class="text-muted ana-section-hint">
          Energía + sueño + ánimo + cumplimiento − hambre
        </span>
      </h4>
      <?php if ($bienestarReciente === null): ?>
        <p class="text-muted">Sin check-ins todavía.</p>
      <?php else:
        $nivelLbl = ['ok'=>'Bienestar alto · sin riesgo', 'warn'=>'Bienestar medio · vigilar', 'bad'=>'Bienestar bajo · RIESGO de abandono'];
      ?>
        <div class="ana-bienestar ana-bienestar--<?= e($bienestarNivel) ?>">
          <div class="ana-bienestar-num">
            <span class="ana-bn-val"><?= $bienestarReciente ?></span>
            <span class="ana-bn-max">/100</span>
          </div>
          <div class="ana-bienestar-info">
            <strong class="ana-bienestar-lbl"><?= e($nivelLbl[$bienestarNivel]) ?></strong>
            <?php if ($bienestarDelta !== null):
              $delClass = abs($bienestarDelta) < 3 ? 'flat' : ($bienestarDelta > 0 ? 'good' : 'bad');
              $delIcon  = abs($bienestarDelta) < 3 ? '→' : ($bienestarDelta > 0 ? '↑' : '↓');
            ?>
              <span class="ana-bienestar-delta ana-delta--<?= e($delClass) ?>">
                <?= $delIcon ?> <?= $bienestarDelta > 0 ? '+' : '' ?><?= $bienestarDelta ?> vs período anterior
              </span>
            <?php else: ?>
              <span class="text-muted" style="font-size: var(--fs-xs);">
                Se compara cuando haya 8+ check-ins
              </span>
            <?php endif; ?>
          </div>
        </div>
        <?php if ($bienestarNivel === 'bad'): ?>
          <div class="alert alert-danger" style="margin-top: var(--sp-3); margin-bottom: 0;">
            ⚠️ El cliente lleva varias semanas con métricas bajas. Considera ajustar el plan, reducir el déficit o contactarle.
          </div>
        <?php elseif ($bienestarNivel === 'warn'): ?>
          <div class="alert alert-warning" style="margin-top: var(--sp-3); margin-bottom: 0;">
            ℹ️ Estado regular. Si la tendencia es negativa, intervén pronto.
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </section>

    <!-- BLOQUE 2: Tendencias por métrica -->
    <?php if (!empty($tendencias) && count($ultimosCheckins) >= 4): ?>
      <section class="ana-section">
        <h4 class="ana-section-title">
          <span>📈 Tendencias por métrica</span>
          <span class="text-muted ana-section-hint">Promedio últimas vs anteriores</span>
        </h4>
        <ul class="ana-tendencias" role="list">
          <?php
            $defs = [
              'hambre'              => ['lbl'=>'Hambre',         'emoji'=>'🍴', 'invertir'=>true],
              'energia'             => ['lbl'=>'Energía',        'emoji'=>'⚡', 'invertir'=>false],
              'sueno'               => ['lbl'=>'Sueño',          'emoji'=>'😴', 'invertir'=>false],
              'cumplimiento_dieta'  => ['lbl'=>'Adherencia',     'emoji'=>'🎯', 'invertir'=>false],
              'animo'               => ['lbl'=>'Ánimo',          'emoji'=>'😊', 'invertir'=>false],
            ];
            foreach ($tendencias as $campo => $t):
              if ($t === null) continue;
              $info = $defs[$campo];
              $color = tend_color($t, $info['invertir']);
              $arrow = tend_arrow($t, $info['invertir']);
          ?>
            <li class="ana-tendencia ana-tendencia--<?= e($color) ?>">
              <span class="ana-tendencia-emoji"><?= $info['emoji'] ?></span>
              <div class="ana-tendencia-body">
                <span class="ana-tendencia-lbl"><?= e($info['lbl']) ?></span>
                <strong class="ana-tendencia-val">
                  <?= number_format($t['actual'], 1, ',', '.') ?>
                  <span class="ana-tendencia-arrow"><?= $arrow ?></span>
                </strong>
                <span class="ana-tendencia-prev">
                  <?php $signo = $t['delta'] > 0 ? '+' : ''; ?>
                  <?= $signo . number_format($t['delta'], 1, ',', '.') ?> vs <?= number_format($t['anterior'], 1, ',', '.') ?>
                </span>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>

    <!-- BLOQUE 3: Pérdida teórica vs real -->
    <?php if ($comparativa !== null): ?>
      <section class="ana-section">
        <h4 class="ana-section-title">
          <span>⚖️ Pérdida teórica vs real</span>
          <span class="text-muted ana-section-hint">Últimos <?= (int)$comparativa['dias'] ?> días</span>
        </h4>
        <div class="ana-comp">
          <div class="ana-comp-col">
            <span class="ana-comp-lbl">Teórica</span>
            <strong class="ana-comp-val"><?= fmt_kg_ficha($comparativa['teorica'], 2) ?></strong>
            <span class="ana-comp-sub">Según el déficit calculado</span>
          </div>
          <div class="ana-comp-col">
            <span class="ana-comp-lbl">Real</span>
            <strong class="ana-comp-val"><?= fmt_kg_ficha($comparativa['real'], 2) ?></strong>
            <span class="ana-comp-sub">
              <?= number_format($comparativa['peso_antes'], 1, ',', '.') ?> → <?= number_format($comparativa['peso_ahora'], 1, ',', '.') ?> kg
            </span>
          </div>
          <div class="ana-comp-col">
            <span class="ana-comp-lbl">Diferencia</span>
            <strong class="ana-comp-val"><?= fmt_kg_ficha($comparativa['diff'], 2) ?></strong>
            <?php if ($comparativa['adherencia'] !== null): ?>
              <span class="ana-comp-sub">Adherencia: <?= (int)round($comparativa['adherencia']) ?>%</span>
            <?php endif; ?>
          </div>
        </div>
        <p class="ana-comp-interp ana-comp-interp--<?= e($comparativa['interp_cls']) ?>">
          <?= e($comparativa['interp']) ?>
        </p>
      </section>
    <?php elseif ($predFicha['ok'] && $pesoActual > 0): ?>
      <section class="ana-section">
        <h4 class="ana-section-title">
          <span>⚖️ Pérdida teórica vs real</span>
        </h4>
        <p class="text-muted">Necesitas al menos 2 pesos del cliente con 7+ días de diferencia para comparar.</p>
      </section>
    <?php endif; ?>

  </article>

  <!-- Objetivos activos -->
  <article class="card">
    <header class="dash-card-header">
      <h3 class="card-title">🎯 Objetivos activos</h3>
      <a class="dash-card-link" href="dietista_objetivos.php?id_cliente=<?= $idC ?>">Gestionar</a>
    </header>
    <?php if (!$objs): ?>
      <p class="text-muted">Sin objetivos activos.</p>
    <?php else: ?>
      <ul class="ficha-objs" role="list">
        <?php foreach ($objs as $o): ?>
          <li>
            <strong><?= e($o['titulo']) ?></strong>
            <?php if ($o['valor_objetivo'] !== null): ?>
              <span class="text-soft">→ <?= e(rtrim(rtrim($o['valor_objetivo'], '0'), '.')) ?> <?= e((string)$o['unidad']) ?></span>
            <?php endif; ?>
            <?php if (!empty($o['fecha_limite'])): ?>
              <span class="text-muted">(antes de <?= e(date('d/m/Y', strtotime($o['fecha_limite']))) ?>)</span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </article>

  <!-- Próxima consulta + última medida -->
  <div class="grid-2">
    <article class="card">
      <h3 class="card-title">📅 Próxima consulta</h3>
      <?php if (!$proxC): ?>
        <p class="text-muted">No hay consultas programadas.</p>
      <?php else: ?>
        <p class="dash-consulta-fecha"><?= e(date('d/m/Y H:i', strtotime($proxC['fecha']))) ?></p>
        <p class="text-soft"><?= e(ucfirst($proxC['tipo'])) ?> · <?= (int)$proxC['duracion_min'] ?> min</p>
      <?php endif; ?>
    </article>

    <article class="card">
      <h3 class="card-title">📏 Última medida</h3>
      <?php if (!$medida): ?>
        <p class="text-muted">Sin medidas registradas.</p>
      <?php else: ?>
        <p class="text-soft"><?= e(date('d/m/Y', strtotime($medida['fecha']))) ?></p>
        <ul class="dash-mini-stats" role="list">
          <?php foreach (['cintura'=>'Cintura','cadera'=>'Cadera','pecho'=>'Pecho'] as $k=>$lbl):
            if ($medida[$k] !== null): ?>
            <li><?= e($lbl) ?>: <strong><?= e(rtrim(rtrim((string)$medida[$k], '0'), '.')) ?> cm</strong></li>
          <?php endif; endforeach; ?>
        </ul>
      <?php endif; ?>
    </article>
  </div>

</main>

<!-- ============================================================
     MODAL: gráfica detallada de pesos
============================================================ -->
<?php if ($totalPesos >= 2): ?>
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
        <?php foreach (array_reverse($rowsPeso) as $r): ?>
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

<!-- ============================================================
     MODAL: perfil completo del paciente
============================================================ -->
<div id="modal_perfil" class="modal-backdrop" hidden>
  <div class="modal modal-wide" role="dialog" aria-modal="true" aria-labelledby="modal_perfil_title">
    <div class="modal-head">
      <h3 class="modal-title" id="modal_perfil_title">🔍 Perfil completo · <?= e($cliente['nombre_completo']) ?></h3>
      <button type="button" class="modal-close" id="modal_perfil_close" aria-label="Cerrar">✕</button>
    </div>
    <div class="modal-body">

      <!-- Datos personales -->
      <section class="perfil-section">
        <h4 class="perfil-section-title">👤 Datos personales</h4>
        <dl class="perfil-dl">
          <dt>Nombre</dt><dd><?= e($cliente['nombre_completo']) ?></dd>
          <dt>Email</dt><dd><?= e($cliente['email']) ?></dd>
          <dt>Cliente desde</dt><dd><?= e(date('d/m/Y', strtotime($cliente['fecha_registro']))) ?> (<?= max(1, (int)floor($diasComoCliente / 7)) ?> sem.)</dd>
          <?php if (!empty($cliente['ultima_actividad'])): ?>
            <dt>Última conexión</dt><dd><?= e(date('d/m/Y H:i', strtotime($cliente['ultima_actividad']))) ?></dd>
          <?php endif; ?>
          <dt>Sexo</dt><dd><?= e($anam['sexo'] ?? '—') ?></dd>
          <dt>Edad</dt><dd><?= $edad > 0 ? $edad . ' años' : '—' ?></dd>
          <?php if (!empty($anam['fecha_nacimiento'])): ?>
            <dt>Fecha nac.</dt><dd><?= e(date('d/m/Y', strtotime($anam['fecha_nacimiento']))) ?></dd>
          <?php endif; ?>
        </dl>
      </section>

      <!-- Antropometría -->
      <section class="perfil-section">
        <h4 class="perfil-section-title">📏 Antropometría</h4>
        <dl class="perfil-dl">
          <dt>Altura</dt><dd><?= !empty($anam['altura_cm']) ? rtrim(rtrim((string)$anam['altura_cm'], '0'), '.') . ' cm' : '—' ?></dd>
          <dt>Peso actual</dt><dd><?= $pesoActual > 0 ? rtrim(rtrim((string)$pesoActual, '0'), '.') . ' kg' : '—' ?></dd>
          <dt>Peso inicial</dt><dd><?= $primerPeso ? rtrim(rtrim((string)$primerPeso['peso_kg'], '0'), '.') . ' kg' : '—' ?></dd>
          <dt>Variación total</dt><dd class="<?= $variacion < 0 ? 'is-down' : ($variacion > 0 ? 'is-up' : '') ?>">
            <?= $totalPesos >= 2 ? ($variacion > 0 ? '+' : '') . number_format($variacion, 1, ',', '') . ' kg' : '—' ?>
          </dd>
          <dt>kg / mes (media)</dt><dd class="<?= $kgPorMes !== null && $kgPorMes < 0 ? 'is-down' : ($kgPorMes !== null && $kgPorMes > 0 ? 'is-up' : '') ?>">
            <?= $kgPorMes !== null ? ($kgPorMes > 0 ? '+' : '') . number_format($kgPorMes, 2, ',', '') . ' kg' : '—' ?>
          </dd>
          <dt>kg / semana</dt><dd>
            <?= $kgPorSemana !== null ? ($kgPorSemana > 0 ? '+' : '') . number_format($kgPorSemana, 2, ',', '') . ' kg' : '—' ?>
          </dd>
          <dt>IMC</dt><dd style="color: <?= e($imcClas[1]) ?>;"><?= $imc !== null ? number_format($imc, 1, ',', '') . ' · ' . e($imcClas[0]) : '—' ?></dd>
          <dt>Días siguiendo plan</dt><dd><?= $diasSeguimiento ?> días</dd>
        </dl>
      </section>

      <!-- Hábitos y actividad -->
      <section class="perfil-section">
        <h4 class="perfil-section-title">🏃 Hábitos y actividad</h4>
        <dl class="perfil-dl">
          <dt>Pasos diarios</dt><dd><?= e($anam['pasos_diarios'] ?? '—') ?></dd>
          <dt>Días de gym/sem</dt><dd><?= e($anam['dias_gym'] ?? '—') ?></dd>
          <dt>Min / sesión</dt><dd><?= !empty($anam['min_sesion']) ? $anam['min_sesion'] . ' min' : '—' ?></dd>
          <dt>Tipo entreno</dt><dd><?= e($anam['tipo_entreno'] ?? '—') ?></dd>
          <dt>Tipo trabajo</dt><dd><?= e($anam['tipo_trabajo'] ?? '—') ?></dd>
          <dt>Factor actividad</dt><dd><?= e($anam['factor_actividad'] ?? '—') ?></dd>
        </dl>
      </section>

      <!-- TDEE detallado -->
      <?php if ($puedeEstrategia): ?>
      <section class="perfil-section">
        <h4 class="perfil-section-title">⚡ Gasto calórico detallado</h4>
        <dl class="perfil-dl">
          <dt>TMB (BMR)</dt><dd><?= number_format($tdee['bmr'], 0, ',', '.') ?> kcal</dd>
          <dt>NEAT pasos</dt><dd>+<?= number_format($tdee['neat_pasos'], 0, ',', '.') ?> kcal</dd>
          <dt>NEAT trabajo</dt><dd>+<?= number_format($tdee['neat_trabajo'], 0, ',', '.') ?> kcal</dd>
          <dt>EAT entreno</dt><dd>+<?= number_format($tdee['eat'], 0, ',', '.') ?> kcal/día gym</dd>
          <dt>TEF</dt><dd>+<?= number_format($tdee['tef'], 0, ',', '.') ?> kcal</dd>
          <dt>TDEE entreno</dt><dd><strong><?= number_format($tdee['tdee_entreno'], 0, ',', '.') ?> kcal</strong></dd>
          <dt>TDEE descanso</dt><dd><strong><?= number_format($tdee['tdee_descanso'], 0, ',', '.') ?> kcal</strong></dd>
          <dt>TDEE ponderado</dt><dd><strong><?= number_format($tdee['tdee_pond'], 0, ',', '.') ?> kcal</strong></dd>
          <dt>Factor equivalente</dt><dd>×<?= number_format($tdee['factor_eq'], 2, ',', '') ?></dd>
        </dl>
      </section>
      <?php endif; ?>

      <!-- Estrategia activa -->
      <?php if (!empty($anam['obj_kcal'])): ?>
      <section class="perfil-section">
        <h4 class="perfil-section-title">🎯 Estrategia nutricional activa</h4>
        <dl class="perfil-dl">
          <dt>Kcal objetivo</dt><dd><strong><?= (int)$anam['obj_kcal'] ?> kcal</strong></dd>
          <dt>Proteínas</dt><dd><?= (int)$anam['obj_p'] ?>g (<?= number_format($cur_factor_p, 2, ',', '') ?> g/kg)</dd>
          <dt>Carbohidratos</dt><dd><?= (int)$anam['obj_c'] ?>g</dd>
          <dt>Grasas</dt><dd><?= (int)$anam['obj_g'] ?>g (<?= number_format($cur_factor_g, 2, ',', '') ?> g/kg)</dd>
          <?php if (!empty($anam['fecha_estrategia'])): ?>
            <dt>Actualizada</dt><dd><?= e(date('d/m/Y H:i', strtotime($anam['fecha_estrategia']))) ?></dd>
          <?php endif; ?>
          <dt>Estrategias guardadas</dt><dd><?= count($historialEstr) ?> en historial</dd>
        </dl>
      </section>
      <?php endif; ?>

      <!-- Adherencia y seguimiento -->
      <section class="perfil-section">
        <h4 class="perfil-section-title">📊 Adherencia y seguimiento</h4>
        <dl class="perfil-dl">
          <dt>Check-ins hechos</dt><dd><?= $totalCheckins ?> de <?= $semanasComoCliente ?> semanas</dd>
          <dt>% adherencia</dt><dd><strong><?= $pctAdherencia ?>%</strong></dd>
          <dt>Registros de peso</dt><dd><?= $totalPesos ?></dd>
        </dl>
      </section>

      <!-- Dietas seguidas (últimos 30 días) -->
      <?php if ($dietas30): ?>
      <section class="perfil-section">
        <h4 class="perfil-section-title">🍽️ Dietas asignadas (últimos 30 días)</h4>
        <ul class="perfil-dietas-list" role="list">
          <?php foreach ($dietas30 as $d): ?>
            <li class="perfil-dieta-item" style="border-left:4px solid <?= e($d['color'] ?: '#2F9E73') ?>;">
              <span class="dash-diet-icon" style="background:<?= e($d['color'] ?: '#2F9E73') ?>;"><?= e($d['icono'] ?: '🍽️') ?></span>
              <div class="perfil-dieta-info">
                <div class="perfil-dieta-name"><?= e($d['nombre']) ?></div>
                <div class="text-muted"><?= (int)$d['kcal_objetivo'] ?> kcal · <?= (int)$d['dias'] ?> días</div>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>

      <!-- Salud / preferencias -->
      <?php if (!empty($anam['alergias']) || !empty($anam['comidas_fav'])): ?>
      <section class="perfil-section">
        <h4 class="perfil-section-title">🥗 Salud y preferencias</h4>
        <?php if (!empty($anam['alergias'])): ?>
          <p class="ficha-block-title">Alergias / intolerancias</p>
          <p class="ficha-block-text"><?= nl2br(e($anam['alergias'])) ?></p>
        <?php endif; ?>
        <?php if (!empty($anam['comidas_fav'])): ?>
          <p class="ficha-block-title">Comidas favoritas / preferencias</p>
          <p class="ficha-block-text"><?= nl2br(e($anam['comidas_fav'])) ?></p>
        <?php endif; ?>
      </section>
      <?php endif; ?>

    </div>
  </div>
</div>

<script>
window.FICHA_DATA = {
  id_cliente: <?= (int)$idC ?>,
  csrf: <?= json_encode(csrf_token()) ?>,
  pesos: <?= json_encode(array_map(function($r){
    return ['fecha'=>$r['fecha_hora'],'peso'=>(float)$r['peso_kg'],'grasa'=>$r['porcentaje_grasa']!==null?(float)$r['porcentaje_grasa']:null];
  }, $rowsPeso), JSON_UNESCAPED_UNICODE) ?>,
  peso_ref: <?= json_encode($pesoActual) ?>,
  factor_p: <?= json_encode($cur_factor_p) ?>,
  factor_g: <?= json_encode($cur_factor_g) ?>,
  kcal: <?= json_encode($cur_kcal) ?>,
  puede_estrategia: <?= $puedeEstrategia ? 'true' : 'false' ?>
};
</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
  'use strict';
  const D = window.FICHA_DATA;
  const $ = id => document.getElementById(id);
  const fmt = v => Math.round(v).toLocaleString('es-ES');

  // ===========================================================
  // GRÁFICA DE PESOS (mini + modal)
  // ===========================================================
  function makePesoChart(canvasId, opts) {
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
      borderWidth: 2, pointRadius: opts.detailed ? 4 : 3, pointHoverRadius: 6,
      tension: 0.3, fill: true, yAxisID: 'y'
    }];
    if (opts.detailed && hasGrasa) {
      datasets.push({
        label: '% grasa', data: dataGrasa,
        borderColor: '#F2A03D', backgroundColor: 'rgba(242,160,61,0.10)',
        borderWidth: 2, pointRadius: 3, borderDash: [4,4],
        tension: 0.3, fill: false, yAxisID: 'y1'
      });
    }

    const scales = {
      x: { grid: { display: !!opts.detailed, color: '#E2E8E2' }, ticks: { font: { size: opts.detailed ? 11 : 10 }, maxRotation: 0, autoSkip: true, maxTicksLimit: opts.detailed ? 12 : 5 } },
      y: { position: 'left', grid: { color: '#E2E8E2' }, ticks: { font: { size: opts.detailed ? 11 : 10 }, callback: v => v + ' kg' } }
    };
    if (opts.detailed && hasGrasa) {
      scales.y1 = { position: 'right', grid: { drawOnChartArea: false }, ticks: { font: { size: 11 }, callback: v => v + '%' } };
    }

    return new Chart(ctx, {
      type: 'line',
      data: { labels, datasets },
      options: {
        responsive: true, maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: !!opts.detailed, position: 'bottom', labels: { font: { size: 12 }, boxWidth: 12 } },
          tooltip: { callbacks: { title: (items) => new Date(D.pesos[items[0].dataIndex].fecha).toLocaleString('es-ES') } }
        },
        scales
      }
    });
  }

  let chartFull = null;
  document.addEventListener('DOMContentLoaded', () => {
    makePesoChart('pesoChart', { detailed: false });

    // Modal evolución peso
    const btnVer = $('btnPesoVerMas');
    const modalP = $('modal_peso_chart');
    if (btnVer && modalP) {
      btnVer.addEventListener('click', () => {
        modalP.hidden = false; document.body.style.overflow = 'hidden';
        setTimeout(() => { if (!chartFull) chartFull = makePesoChart('pesoChartFull', { detailed: true }); else chartFull.resize(); }, 50);
      });
      $('modal_peso_close').addEventListener('click', () => { modalP.hidden = true; document.body.style.overflow = ''; });
      modalP.addEventListener('click', e => { if (e.target === modalP) { modalP.hidden = true; document.body.style.overflow = ''; } });
    }

    // Modal perfil completo
    const btnPerf = $('btnPerfilCompleto');
    const modalPerf = $('modal_perfil');
    if (btnPerf && modalPerf) {
      btnPerf.addEventListener('click', () => { modalPerf.hidden = false; document.body.style.overflow = 'hidden'; });
      $('modal_perfil_close').addEventListener('click', () => { modalPerf.hidden = true; document.body.style.overflow = ''; });
      modalPerf.addEventListener('click', e => { if (e.target === modalPerf) { modalPerf.hidden = true; document.body.style.overflow = ''; } });
    }

    document.addEventListener('keydown', e => {
      if (e.key !== 'Escape') return;
      if (modalP && !modalP.hidden) { modalP.hidden = true; document.body.style.overflow = ''; }
      if (modalPerf && !modalPerf.hidden) { modalPerf.hidden = true; document.body.style.overflow = ''; }
    });
  });

  // ===========================================================
  // ESTRATEGIA (preview en tiempo real + GUARDAR MANUAL)
  // ===========================================================
  if (!D.puede_estrategia) return;

  const inKcal = $('estr_kcal');
  const inP    = $('estr_factor_p');
  const inG    = $('estr_factor_g');
  const inNombre = $('estr_nombre');
  const lblP   = $('estr_lbl_p');
  const lblG   = $('estr_lbl_g');
  const gP     = $('estr_g_p');
  const gC     = $('estr_g_c');
  const gG     = $('estr_g_g');
  const kP     = $('estr_k_p');
  const kC     = $('estr_k_c');
  const kG     = $('estr_k_g');
  const aviso  = $('estrAviso');
  const status = $('estrStatus');
  const btnGuardar = $('estrBtnGuardar');

  // Valores iniciales para detectar modificaciones
  const initialState = {
    fp: parseFloat(D.factor_p),
    fg: parseFloat(D.factor_g),
    kcal: parseInt(D.kcal, 10)
  };

  function setStatus(state, txt) {
    if (!status) return;
    status.dataset.state = state;
    status.textContent = txt || (state === 'saving' ? '● guardando…' : state === 'ok' ? '● guardado' : state === 'error' ? '● error' : '●');
  }

  function actualizarBoton(modificado) {
    if (modificado) {
      btnGuardar.dataset.state = 'modified';
      btnGuardar.textContent = '💾 Guardar estrategia';
      btnGuardar.disabled = false;
    } else {
      btnGuardar.dataset.state = 'saved';
      btnGuardar.textContent = '✓ Estrategia actual guardada';
      btnGuardar.disabled = true;
    }
  }

  function recalc() {
    const peso = parseFloat(D.peso_ref) || 0;
    const fp = parseFloat(inP.value) || 0;
    const fg = parseFloat(inG.value) || 0;
    const kcal = parseInt(inKcal.value, 10) || 0;

    lblP.textContent = fp.toFixed(2).replace('.', ',');
    lblG.textContent = fg.toFixed(2).replace('.', ',');

    const gramosP = peso * fp;
    const gramosG = peso * fg;
    const kcalP = gramosP * 4;
    const kcalG = gramosG * 9;
    const kcalRestante = kcal - kcalP - kcalG;
    const gramosC = kcalRestante / 4;

    gP.textContent = Math.round(gramosP) + ' g';
    gG.textContent = Math.round(gramosG) + ' g';
    gC.textContent = Math.round(Math.max(0, gramosC)) + ' g';
    kP.textContent = fmt(kcalP) + ' kcal';
    kG.textContent = fmt(kcalG) + ' kcal';
    kC.textContent = fmt(Math.max(0, kcalRestante)) + ' kcal';

    if (kcalRestante < 0) {
      aviso.hidden = false;
      aviso.textContent = '⚠️ Proteína + grasa superan el objetivo. Sube las kcal o baja los factores.';
      aviso.className = 'ficha-estr-aviso ficha-estr-aviso--danger';
    } else if (kcalRestante < kcal * 0.15) {
      aviso.hidden = false;
      aviso.textContent = '⚠️ Quedan muy pocas kcal para carbohidratos (<15% del total).';
      aviso.className = 'ficha-estr-aviso ficha-estr-aviso--warn';
    } else {
      aviso.hidden = true;
    }

    // Detectar si hay cambios
    const modificado = (
      Math.abs(fp - initialState.fp) > 0.001 ||
      Math.abs(fg - initialState.fg) > 0.001 ||
      kcal !== initialState.kcal
    );
    actualizarBoton(modificado);

    return { fp, fg, kcal, gramosP, gramosC: Math.max(0, gramosC), gramosG };
  }

  async function guardar() {
    const r = recalc();
    if (r.kcal < 800 || r.kcal > 6000) { setStatus('error', '● kcal fuera de rango'); return; }

    btnGuardar.disabled = true;
    setStatus('saving');

    const fd = new FormData();
    fd.append('_csrf', D.csrf);
    fd.append('factor_p', r.fp);
    fd.append('factor_g', r.fg);
    fd.append('kcal', r.kcal);
    fd.append('gramos_p', Math.round(r.gramosP));
    fd.append('gramos_c', Math.round(r.gramosC));
    fd.append('gramos_g', Math.round(r.gramosG));
    fd.append('nombre', inNombre.value.trim());

    try {
      const res = await fetch('<?= e($base) ?>ajax/ajax_estrategia.php?accion=guardar&id_cliente=' + D.id_cliente, {
        method: 'POST', body: fd
      });
      const j = await res.json();
      if (j.ok) {
        initialState.fp = r.fp;
        initialState.fg = r.fg;
        initialState.kcal = r.kcal;
        inNombre.value = '';   // limpiar para la siguiente
        actualizarBoton(false);
        setStatus('ok');
        await refrescarHistorial();
      } else {
        setStatus('error', '● ' + (j.error || 'error'));
        btnGuardar.disabled = false;
      }
    } catch (e) {
      setStatus('error', '● sin conexión');
      btnGuardar.disabled = false;
    }
  }

  async function refrescarHistorial() {
    try {
      const res = await fetch('<?= e($base) ?>ajax/ajax_estrategia.php?accion=historial&id_cliente=' + D.id_cliente);
      const j = await res.json();
      if (!j.ok) return;
      const list = $('estrHistList');
      const count = $('estrHistCount');
      if (count) count.textContent = j.items.length;
      if (!list) return;
      list.innerHTML = '';
      if (!j.items.length) {
        list.innerHTML = '<li class="ficha-estr-hist-empty">Aún no hay estrategias guardadas.</li>';
        return;
      }
      j.items.forEach(h => {
        const li = document.createElement('li');
        li.className = 'ficha-estr-hist-item';
        li.dataset.id = h.id;
        const fecha = new Date(h.fecha.replace(' ', 'T'));
        const fechaTxt = fecha.toLocaleString('es-ES', { day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit' });
        const fp = parseFloat(h.factor_p).toFixed(2).replace('.', ',');
        const fg = parseFloat(h.factor_g).toFixed(2).replace('.', ',');
        const nombreHtml = h.nombre ? `<strong class="ficha-estr-hist-nombre">${escapeHtml(h.nombre)}</strong> · ` : '';
        const dataNombre = h.nombre ? h.nombre.replace(/"/g, '&quot;') : '';
        li.innerHTML = `
          <div class="ficha-estr-hist-info">
            <div class="ficha-estr-hist-fecha">${nombreHtml}${fechaTxt}</div>
            <div class="ficha-estr-hist-vals">
              <span>${h.kcal} kcal</span> ·
              <span>P ${fp}g/kg (${h.gramos_p}g)</span> ·
              <span>G ${fg}g/kg (${h.gramos_g}g)</span> ·
              <span>C ${h.gramos_c}g</span>
            </div>
          </div>
          <div class="ficha-estr-hist-actions">
            <button type="button" class="btn btn-outline btn-mini" data-act="reusar"
              data-fp="${h.factor_p}" data-fg="${h.factor_g}" data-k="${h.kcal}" data-nombre="${dataNombre}">↺ Reusar</button>
            <button type="button" class="btn btn-ghost btn-mini" data-act="borrar" aria-label="Borrar">🗑️</button>
          </div>
        `;
        list.appendChild(li);
      });
    } catch (e) {}
  }

  // helper de escape para los nombres en JS
  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, m => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[m]));
  }

  async function borrarEntrada(id) {
    if (!confirm('¿Borrar esta entrada del historial?')) return;
    const fd = new FormData();
    fd.append('_csrf', D.csrf);
    fd.append('id', id);
    try {
      const res = await fetch('<?= e($base) ?>ajax/ajax_estrategia.php?accion=borrar&id_cliente=' + D.id_cliente, {
        method: 'POST', body: fd
      });
      const j = await res.json();
      if (j.ok) refrescarHistorial();
    } catch (e) {}
  }

  document.addEventListener('DOMContentLoaded', () => {
    recalc();  // pinta los valores iniciales sin marcar como modificado

    inKcal.addEventListener('input', recalc);
    inP.addEventListener('input', recalc);
    inG.addEventListener('input', recalc);

    btnGuardar.addEventListener('click', guardar);

    const list = $('estrHistList');
    if (list) {
      list.addEventListener('click', e => {
        const btn = e.target.closest('button[data-act]');
        if (!btn) return;
        const li = btn.closest('.ficha-estr-hist-item');
        if (!li) return;
        if (btn.dataset.act === 'reusar') {
          inP.value = btn.dataset.fp;
          inG.value = btn.dataset.fg;
          inKcal.value = btn.dataset.k;
          inNombre.value = btn.dataset.nombre || '';
          recalc();  // ahora SÍ se marca como modificado porque cambian los inputs
        } else if (btn.dataset.act === 'borrar') {
          borrarEntrada(li.dataset.id);
        }
      });
    }
  });
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>