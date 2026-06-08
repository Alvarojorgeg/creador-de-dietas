<?php
/**
 * /ajax/ajax_alternativas.php
 * Sugerencias de intercambio de alimentos (swaps) con macros equivalentes.
 *   GET ?id_alimento=N&cantidad=120
 *
 * Lógica:
 *  1. Calcula los macros del alimento original a la cantidad dada.
 *  2. Determina el macro DOMINANTE (el que aporta más kcal).
 *  3. Para cada candidato: cantidad equivalente que iguala el macro dominante.
 *  4. Score = 1 − (distancia Manhattan entre proporciones de P/C/G) / 2.
 *     Score=1 perfectamente similar, Score=0 totalmente distinto.
 *  5. Devuelve top 12 ordenados por score.
 */
require_once __DIR__ . '/../includes/conexion.php';
header('Content-Type: application/json; charset=utf-8');

if (!esta_logueado()) json_out(['ok'=>false,'error'=>'sesion'], 401);

$idAlim   = (int)($_GET['id_alimento'] ?? 0);
$cantidad = (float)($_GET['cantidad'] ?? 0);
if ($idAlim <= 0 || $cantidad <= 0) json_out(['ok'=>false,'error'=>'params'], 400);

// ---- Cargar original ----
$stmt = $conn->prepare(
    "SELECT id, nombre, marca, racion_base_gr, kcal, proteinas, carbos, grasas
     FROM alimentos WHERE id=?"
);
$stmt->bind_param('i', $idAlim);
$stmt->execute();
$orig = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$orig) json_out(['ok'=>false,'error'=>'no_encontrado'], 404);

$base   = max(1, (float)$orig['racion_base_gr']);
$factor = $cantidad / $base;
$orig_kcal = (float)$orig['kcal']      * $factor;
$orig_p    = (float)$orig['proteinas'] * $factor;
$orig_c    = (float)$orig['carbos']    * $factor;
$orig_g    = (float)$orig['grasas']    * $factor;

// ---- Macro dominante por kcal ----
$kP = $orig_p * 4;
$kC = $orig_c * 4;
$kG = $orig_g * 9;
$totalK = $kP + $kC + $kG;
if ($totalK <= 0) json_out(['ok'=>false,'error'=>'sin_macros'], 400);

$dominante = 'proteinas';
$maxK = $kP;
if ($kC > $maxK) { $maxK = $kC; $dominante = 'carbos'; }
if ($kG > $maxK) { $maxK = $kG; $dominante = 'grasas'; }
$orig_dom_g = ($dominante === 'proteinas') ? $orig_p
            : (($dominante === 'carbos')   ? $orig_c : $orig_g);

// Distribución % macros del original
$origPct = ['p' => $kP/$totalK, 'c' => $kC/$totalK, 'g' => $kG/$totalK];

// ---- Buscar candidatos: que tengan algo del macro dominante ----
$stmt = $conn->prepare(
    "SELECT id, nombre, marca, racion_base_gr, kcal, proteinas, carbos, grasas
     FROM alimentos
     WHERE id <> ? AND aprobado_global = 1 AND $dominante > 0
     LIMIT 800"
);
$stmt->bind_param('i', $idAlim);
$stmt->execute();
$todos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$candidatos = [];
foreach ($todos as $c) {
    $cBase = max(1, (float)$c['racion_base_gr']);
    $cDom  = (float)$c[$dominante];
    if ($cDom <= 0) continue;

    // gramos del candidato por gramo de producto (densidad del macro)
    $cDomPerG = $cDom / $cBase;
    if ($cDomPerG <= 0) continue;

    // Cantidad necesaria del candidato para igualar el macro dominante
    $eqGr = $orig_dom_g / $cDomPerG;
    if ($eqGr < 5 || $eqGr > 1500) continue;  // descartar absurdos

    $f      = $eqGr / $cBase;
    $cKcal  = (float)$c['kcal']      * $f;
    $cP     = (float)$c['proteinas'] * $f;
    $cC     = (float)$c['carbos']    * $f;
    $cG     = (float)$c['grasas']    * $f;

    // Score de similitud (proporciones P/C/G)
    $cKp = $cP * 4; $cKc = $cC * 4; $cKg = $cG * 9;
    $cTotal = $cKp + $cKc + $cKg;
    if ($cTotal <= 0) continue;
    $cPct = ['p' => $cKp/$cTotal, 'c' => $cKc/$cTotal, 'g' => $cKg/$cTotal];

    $dist  = abs($cPct['p'] - $origPct['p'])
           + abs($cPct['c'] - $origPct['c'])
           + abs($cPct['g'] - $origPct['g']);
    $score = max(0, 1 - ($dist / 2));

    // Penalizar diferencias muy grandes en kcal (>30%)
    $kcalDiffPct = $orig_kcal > 0 ? abs($cKcal - $orig_kcal) / $orig_kcal : 0;
    if ($kcalDiffPct > 0.30) $score *= max(0.5, 1 - ($kcalDiffPct - 0.30));

    $candidatos[] = [
        'id'        => (int)$c['id'],
        'nombre'    => $c['nombre'],
        'marca'     => $c['marca'],
        'cantidad'  => (int)round($eqGr),
        'kcal'      => (int)round($cKcal),
        'p'         => round($cP, 1),
        'c'         => round($cC, 1),
        'g'         => round($cG, 1),
        'kcal_diff' => (int)round($cKcal - $orig_kcal),
        'score'     => round($score, 3),
    ];
}

usort($candidatos, function($a, $b) { return $b['score'] <=> $a['score']; });
$candidatos = array_slice($candidatos, 0, 12);

// Etiqueta legible del macro dominante
$labelDom = ['proteinas'=>'proteína','carbos'=>'carbohidratos','grasas'=>'grasa'][$dominante];

json_out([
    'ok' => true,
    'original' => [
        'id'        => (int)$orig['id'],
        'nombre'    => $orig['nombre'],
        'marca'     => $orig['marca'],
        'cantidad'  => (int)round($cantidad),
        'kcal'      => (int)round($orig_kcal),
        'p'         => round($orig_p, 1),
        'c'         => round($orig_c, 1),
        'g'         => round($orig_g, 1),
        'dominante' => $dominante,
        'dom_label' => $labelDom,
    ],
    'alternativas' => $candidatos,
]);
