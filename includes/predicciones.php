<?php
/**
 * Helpers de PREDICCIÓN para cliente y dietista.
 *
 * MODELO MATEMÁTICO (auditado):
 *   - Iteración día a día en la ventana [desde, hasta]
 *   - balance_dia = TDEE_pond - kcal_dieta_asignada(dia)
 *   - Si día vacío → balance = 0 (mantenimiento)
 *   - Si día con N>1 dietas (error BD) → AVG(kcal) + flag "ambiguo"
 *   - balance_total = Σ balance_dia
 *   - kg_total = balance_total / 7700
 *
 * Funciones públicas:
 *   - calcular_tdee_basico($a, $peso)        TDEE entreno/descanso/ponderado
 *   - obtener_kcal_por_fecha($conn,$id,$d,$h) Mapa fecha → kcal (deduplicado)
 *   - predecir_cliente_rango(...)             Predicción para un rango arbitrario
 *   - predecir_cliente($conn, $id)            Wrapper: próximos 30 días (compat)
 *   - fecha_estimada_objetivo(...)            ETA de un objetivo de peso/grasa
 */

if (!function_exists('calcular_tdee_basico')) {
function calcular_tdee_basico(array $a, float $peso): ?array {
    if ($peso <= 0) return null;
    if (empty($a['fecha_nacimiento']) || empty($a['altura_cm'])) return null;

    try {
        $bd = new DateTime((string)$a['fecha_nacimiento']);
        $edad = (int)$bd->diff(new DateTime())->y;
    } catch (Throwable $e) { return null; }
    if ($edad < 10 || $edad > 100) return null;

    $altura = (float)$a['altura_cm'];
    $sexo   = strtoupper(substr((string)($a['sexo'] ?? 'M'), 0, 1));

    if (in_array($sexo, ['F','W','H'], true)) {
        $bmr = 10*$peso + 6.25*$altura - 5*$edad - 161;
    } else {
        $bmr = 10*$peso + 6.25*$altura - 5*$edad + 5;
    }

    $pasos     = max(0, (int)($a['pasos_diarios'] ?? 5000));
    $neatPasos = $pasos * 0.04;

    $trabajoMap = ['sedentario'=>0,'ligero'=>150,'moderado'=>350,'activo'=>500,'muy_activo'=>700];
    $neatTrab   = $trabajoMap[strtolower((string)($a['tipo_trabajo'] ?? ''))] ?? 0;

    $minSes  = max(0, (int)($a['min_sesion'] ?? 60));
    $diasGym = max(0, min(7, (int)($a['dias_gym'] ?? 0)));
    $entrenoMap = ['pesas'=>7,'fuerza'=>7,'cardio'=>9,'mixto'=>8,'hiit'=>11,'crossfit'=>10,'yoga'=>4];
    $kPorMin = $entrenoMap[strtolower((string)($a['tipo_entreno'] ?? ''))] ?? 7;
    $eat = $minSes * $kPorMin;

    $factor = (float)($a['factor_actividad'] ?? 1.0); if ($factor <= 0) $factor = 1.0;
    $base       = $bmr + ($neatPasos + $neatTrab) * $factor;
    $tdeeDesc   = $base * 1.1;
    $tdeeEntr   = ($base + $eat) * 1.1;
    $tdeePond   = ($tdeeDesc * (7 - $diasGym) + $tdeeEntr * $diasGym) / 7;

    return [
        'ent'           => (int)round($tdeeEntr),
        'desc'          => (int)round($tdeeDesc),
        'pond'          => (int)round($tdeePond),
        'bmr'           => (int)round($bmr),
        'neat_pasos'    => (int)round($neatPasos * $factor),
        'neat_trabajo'  => (int)round($neatTrab * $factor),
        'eat'           => (int)round($eat),
        'dias_gym'      => $diasGym,
    ];
}
}

if (!function_exists('obtener_kcal_por_fecha')) {
function obtener_kcal_por_fecha(mysqli $conn, int $idCliente, string $desde, string $hasta): array {
    $stmt = $conn->prepare(
        "SELECT ca.fecha_asignada,
                AVG(db.kcal_objetivo) AS kcal_avg,
                COUNT(*)              AS n_dietas
         FROM calendario_asignaciones ca
         JOIN dietas_base db ON db.id = ca.id_dieta
         WHERE ca.id_cliente = ?
           AND ca.fecha_asignada BETWEEN ? AND ?
           AND db.kcal_objetivo > 0
         GROUP BY ca.fecha_asignada"
    );
    $stmt->bind_param('iss', $idCliente, $desde, $hasta);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $mapa = [];
    foreach ($rows as $r) {
        $mapa[$r['fecha_asignada']] = [
            'kcal'     => (float)$r['kcal_avg'],
            'ambiguo'  => (int)$r['n_dietas'] > 1,
            'n_dietas' => (int)$r['n_dietas'],
        ];
    }
    return $mapa;
}
}

/**
 * Predicción para un rango arbitrario [desde, hasta].
 *
 * @param mysqli $conn
 * @param int    $idCliente
 * @param string $desde         'Y-m-d' (inclusive)
 * @param string $hasta         'Y-m-d' (inclusive)
 * @param bool   $usarFallback  Si true y no hay calendario en el rango, usar la última dieta del
 *                              cliente como aproximación constante. Útil para "próximos 30 días".
 * @return array Ver claves en $out.
 */
if (!function_exists('predecir_cliente_rango')) {
function predecir_cliente_rango(
    mysqli $conn,
    int $idCliente,
    string $desde,
    string $hasta,
    bool $usarFallback = false
): array {
    // Validación de fechas
    $tsDesde = strtotime($desde);
    $tsHasta = strtotime($hasta);
    if ($tsDesde === false || $tsHasta === false || $tsHasta < $tsDesde) {
        return [
            'ok' => false, 'razon' => 'Rango de fechas inválido.',
            'desde' => $desde, 'hasta' => $hasta,
        ];
    }
    $diasVentana = (int)round(($tsHasta - $tsDesde) / 86400) + 1;
    if ($diasVentana > 365) $diasVentana = 365;  // límite de seguridad

    $out = [
        'ok'                   => false,
        'razon'                => '',
        'desde'                => $desde,
        'hasta'                => $hasta,
        'dias_ventana'         => $diasVentana,
        'peso'                 => null,
        'tdee_ent'             => null,
        'tdee_desc'            => null,
        'tdee_pond'            => null,
        'bmr'                  => null,
        'neat_pasos'           => null,
        'neat_trabajo'         => null,
        'eat'                  => null,
        'dias_gym'             => null,
        'kcal_media'           => null,
        'deficit_dia'          => null,
        'kg_dia'               => null,    // ritmo PROMEDIO del rango = balance_total / dias_ventana / 7700
        'kg_semana'            => null,    // proyectado a 7 días al mismo ritmo
        'kg_mes'               => null,    // total real del rango (balance_total / 7700)
        'dietas_breakdown'     => [],
        'dias_con_dieta'       => 0,
        'dias_vacios'          => 0,
        'dias_ambiguos'        => 0,
        'balance_total'        => 0,
        'usado_fallback'       => false,
    ];

    // 1) Anamnesis
    $stmt = $conn->prepare("SELECT * FROM fichas_anamnesis WHERE id_cliente=?");
    $stmt->bind_param('i', $idCliente); $stmt->execute();
    $a = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$a || empty($a['fecha_nacimiento']) || empty($a['altura_cm'])) {
        $out['razon'] = 'Falta completar el cuestionario inicial.';
        return $out;
    }

    // 2) Peso
    $stmt = $conn->prepare(
        "SELECT peso_kg FROM progresos_metricas
         WHERE id_cliente=? AND peso_kg IS NOT NULL AND peso_kg > 0
         ORDER BY fecha_hora DESC LIMIT 1"
    );
    $stmt->bind_param('i', $idCliente); $stmt->execute();
    $peso = (float)($stmt->get_result()->fetch_assoc()['peso_kg'] ?? 0); $stmt->close();
    if ($peso <= 0) {
        $out['razon'] = 'Aún no has registrado tu peso.';
        return $out;
    }

    // 3) TDEE
    $tdee = calcular_tdee_basico($a, $peso);
    if (!$tdee) {
        $out['razon'] = 'No se puede calcular tu TDEE.';
        return $out;
    }

    // 4) Mapa fecha → kcal (deduplicado) en el rango
    $mapa = obtener_kcal_por_fecha($conn, $idCliente, $desde, $hasta);

    // Fallback opcional si NO hay nada asignado en el rango
    if (empty($mapa) && $usarFallback) {
        $stmt = $conn->prepare(
            "SELECT kcal_objetivo FROM dietas_base
             WHERE id_cliente = ? AND kcal_objetivo > 0
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->bind_param('i', $idCliente); $stmt->execute();
        $d = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if ($d) {
            $out['usado_fallback'] = true;
            $kcalFallback = (float)$d['kcal_objetivo'];
            $cursor = new DateTime($desde);
            $endTs  = strtotime($hasta);
            while ($cursor->getTimestamp() <= $endTs) {
                $mapa[$cursor->format('Y-m-d')] = ['kcal' => $kcalFallback, 'ambiguo' => false, 'n_dietas' => 1];
                $cursor->modify('+1 day');
            }
        }
    }

    // 5) Sumatorio día a día (núcleo del cálculo)
    $balanceTotal = 0.0;
    $conDieta     = 0;
    $vacios       = 0;
    $ambig        = 0;
    $sumKcalConDieta = 0.0;

    $cursor = new DateTime($desde);
    $endTs  = strtotime($hasta);
    $contadorSeguridad = 0;
    while ($cursor->getTimestamp() <= $endTs && $contadorSeguridad < 400) {
        $f = $cursor->format('Y-m-d');
        if (isset($mapa[$f])) {
            $kDia = $mapa[$f]['kcal'];
            $bDia = $tdee['pond'] - $kDia;
            $balanceTotal += $bDia;
            $sumKcalConDieta += $kDia;
            $conDieta++;
            if ($mapa[$f]['ambiguo']) $ambig++;
        } else {
            $vacios++;
        }
        $cursor->modify('+1 day');
        $contadorSeguridad++;
    }

    // 6) Métricas derivadas
    $kgTotalRango = $balanceTotal / 7700.0;
    $kgDiaPromedio = $diasVentana > 0 ? ($balanceTotal / $diasVentana / 7700.0) : 0.0;
    $kgSemanaProyectado = $kgDiaPromedio * 7;
    $kcalMediaConDieta = $conDieta > 0 ? ($sumKcalConDieta / $conDieta) : null;
    $deficitMediaConDieta = $conDieta > 0 ? ($balanceTotal / $conDieta) : null;

    // 7) Desglose por dieta (informativo)
    $stmt = $conn->prepare(
        "SELECT db.id, db.nombre, db.icono, db.color, db.kcal_objetivo,
                COUNT(DISTINCT ca.fecha_asignada) AS dias
         FROM calendario_asignaciones ca
         JOIN dietas_base db ON db.id = ca.id_dieta
         WHERE ca.id_cliente = ?
           AND ca.fecha_asignada BETWEEN ? AND ?
           AND db.kcal_objetivo > 0
         GROUP BY db.id, db.nombre, db.icono, db.color, db.kcal_objetivo
         ORDER BY dias DESC"
    );
    $stmt->bind_param('iss', $idCliente, $desde, $hasta);
    $stmt->execute();
    $breakdown = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $out['ok']               = true;
    $out['peso']             = $peso;
    $out['tdee_ent']         = $tdee['ent'];
    $out['tdee_desc']        = $tdee['desc'];
    $out['tdee_pond']        = $tdee['pond'];
    $out['bmr']              = $tdee['bmr'];
    $out['neat_pasos']       = $tdee['neat_pasos'];
    $out['neat_trabajo']     = $tdee['neat_trabajo'];
    $out['eat']              = $tdee['eat'];
    $out['dias_gym']         = $tdee['dias_gym'];
    $out['kcal_media']       = $kcalMediaConDieta !== null ? (int)round($kcalMediaConDieta) : null;
    $out['deficit_dia']      = $deficitMediaConDieta !== null ? (int)round($deficitMediaConDieta) : null;
    $out['kg_dia']           = $kgDiaPromedio;
    $out['kg_semana']        = $kgSemanaProyectado;
    $out['kg_mes']           = $kgTotalRango;     // total real del rango (no fijo a 30)
    $out['balance_total']    = (int)round($balanceTotal);
    $out['dias_con_dieta']   = $conDieta;
    $out['dias_vacios']      = $vacios;
    $out['dias_ambiguos']    = $ambig;
    $out['dietas_breakdown'] = $breakdown;
    return $out;
}
}

/**
 * Wrapper: próximos 30 días desde hoy (mantiene compat con código anterior).
 * Las claves devueltas son las mismas que predecir_cliente_rango, además se mapean
 * algunas para compatibilidad inversa (dias_con_dieta_7/30, etc.).
 */
if (!function_exists('predecir_cliente')) {
function predecir_cliente(mysqli $conn, int $idCliente): array {
    $desde = date('Y-m-d');
    $hasta = date('Y-m-d', strtotime('+29 days'));
    $r = predecir_cliente_rango($conn, $idCliente, $desde, $hasta, true);

    // Aliases para compatibilidad con vistas existentes
    $r['dias_con_dieta_30'] = $r['dias_con_dieta'] ?? 0;
    $r['dias_vacios_30']    = $r['dias_vacios']    ?? 0;
    $r['dias_ambiguos_30']  = $r['dias_ambiguos']  ?? 0;
    $r['balance_mes']       = $r['balance_total']  ?? 0;

    // dias_con_dieta_7: contar los primeros 7 días del mapa
    $conDieta7 = 0; $vacios7 = 0; $ambig7 = 0; $balanceSem = 0.0;
    if ($r['ok']) {
        $mapa = obtener_kcal_por_fecha($conn, $idCliente, $desde, date('Y-m-d', strtotime('+6 days')));
        if (empty($mapa) && !empty($r['usado_fallback'])) {
            // Si se usó fallback, los próximos 7 también
            $kcalAvg = (int)$r['kcal_media'];
            $tdeePond = (int)$r['tdee_pond'];
            $balanceSem = ($tdeePond - $kcalAvg) * 7;
            $conDieta7  = 7;
        } else {
            $cur = new DateTime($desde);
            for ($i = 0; $i < 7; $i++) {
                $f = $cur->format('Y-m-d');
                if (isset($mapa[$f])) {
                    $balanceSem += ($r['tdee_pond'] - $mapa[$f]['kcal']);
                    $conDieta7++;
                    if ($mapa[$f]['ambiguo']) $ambig7++;
                } else $vacios7++;
                $cur->modify('+1 day');
            }
        }
        // Sobrescribir kg_semana con cálculo real de los próximos 7 días
        $r['kg_semana'] = $balanceSem / 7700.0;
        $r['balance_semana'] = (int)round($balanceSem);
    }
    $r['dias_con_dieta_7']  = $conDieta7;
    $r['dias_vacios_7']     = $vacios7;
    $r['dias_ambiguos_7']   = $ambig7;
    return $r;
}
}

if (!function_exists('fecha_estimada_objetivo')) {
function fecha_estimada_objetivo(array $obj, float $valorActual, float $cambioPorDia): ?array {
    if (!in_array($obj['tipo'] ?? '', ['peso','grasa'], true)) return null;
    if ($obj['valor_objetivo'] === null) return null;
    if ($valorActual <= 0) return null;

    $valObj  = (float)$obj['valor_objetivo'];
    $diff    = $valorActual - $valObj;
    $sentido = abs($diff) < 0.05 ? 'igual' : ($diff > 0 ? 'bajar' : 'subir');

    if ($sentido === 'igual') {
        return ['dias' => 0, 'fecha' => date('Y-m-d'),
                'mensaje' => '¡Ya estás en tu objetivo!',
                'sentido' => 'igual', 'rumbo_ok' => true];
    }

    $rumboOk = ($sentido === 'bajar' && $cambioPorDia > 0.001) ||
               ($sentido === 'subir' && $cambioPorDia < -0.001);
    if (!$rumboOk) {
        $msg = ($sentido === 'bajar')
            ? 'Con el ritmo actual no estás bajando de peso.'
            : 'Con el ritmo actual no estás subiendo de peso.';
        return ['dias' => null, 'fecha' => null, 'mensaje' => $msg,
                'sentido' => $sentido, 'rumbo_ok' => false];
    }

    $cambioAbs = abs($cambioPorDia);
    if ($cambioAbs < 1e-6) {
        return ['dias' => null, 'fecha' => null,
                'mensaje' => 'Sin cambio estimado.', 'sentido' => $sentido, 'rumbo_ok' => false];
    }

    $dias = abs($diff) / $cambioAbs;
    if ($dias > 730) {
        return ['dias' => (int)$dias, 'fecha' => null,
                'mensaje' => 'A este ritmo necesitarías más de 2 años. Considera ajustar el plan.',
                'sentido' => $sentido, 'rumbo_ok' => true];
    }

    $fecha = (new DateTime('today'))->modify('+' . (int)round($dias) . ' days')->format('Y-m-d');
    return ['dias' => (int)round($dias), 'fecha' => $fecha,
            'mensaje' => '', 'sentido' => $sentido, 'rumbo_ok' => true];
}
}