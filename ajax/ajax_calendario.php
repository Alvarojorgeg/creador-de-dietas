<?php
/**
 * /ajax/ajax_calendario.php
 *   GET  ?accion=mes&id_cliente=N&mes=YYYY-MM
 *   POST ?accion=paint|erase|del_uno|clear_all&id_cliente=N
 */
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/conexion.php';

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
        echo json_encode(['ok'=>false,'error'=>'php_fatal','detail'=>$err['message'].' @ '.basename($err['file']).':'.$err['line']]);
    }
});

header('Content-Type: application/json; charset=utf-8');

// =================================================================
// HELPER: calcular TDEE (mismo modelo que en ficha/dietas)
// =================================================================
function calcular_tdee_calendar(array $a, float $peso): array {
    $out = ['entreno'=>0.0,'descanso'=>0.0,'pond'=>0.0];
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

    return ['entreno'=>$tdeeEntreno, 'descanso'=>$tdeeDescanso, 'pond'=>$tdeePond];
}

try {

    if (!esta_logueado()) json_out(['ok'=>false,'error'=>'sesion'], 401);

    $rol       = rol_actual();
    $uid       = (int)usuario_id();
    $accion    = $_GET['accion'] ?? '';
    $idCliente = (int)($_REQUEST['id_cliente'] ?? 0);

    $idDietistaCtx = 0;

    if ($rol === 'cliente') {
        if ($idCliente <= 0) $idCliente = $uid;
        if ($idCliente !== $uid) json_out(['ok'=>false,'error'=>'permiso'], 403);
        $stmt = $conn->prepare("SELECT id_dietista FROM usuarios WHERE id=? AND activo=1");
        $stmt->bind_param('i', $uid); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $idDietistaCtx = $row ? (int)$row['id_dietista'] : 0;
        $stmt->close();
    }
    elseif ($rol === 'dietista') {
        if ($idCliente <= 0) json_out(['ok'=>false,'error'=>'cliente'], 400);
        $stmt = $conn->prepare("SELECT id FROM usuarios WHERE id=? AND id_dietista=? AND rol='cliente' AND activo=1");
        $stmt->bind_param('ii', $idCliente, $uid); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$row) json_out(['ok'=>false,'error'=>'propiedad'], 403);
        $idDietistaCtx = $uid;
    }
    else json_out(['ok'=>false,'error'=>'rol'], 403);

    // Helper fechas
    $filtrarFechas = function ($arr) {
        $out = [];
        if (!is_array($arr)) return $out;
        foreach ($arr as $f) if (is_string($f) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) $out[$f] = true;
        return array_keys($out);
    };

    switch ($accion) {

        case 'mes': {
            $mes = $_GET['mes'] ?? date('Y-m');
            if (!preg_match('/^\d{4}-\d{2}$/', $mes)) $mes = date('Y-m');

            $primero = DateTime::createFromFormat('Y-m-d', $mes . '-01');
            if (!$primero) json_out(['ok'=>false,'error'=>'mes_invalido'], 400);
            $ultimo     = (clone $primero)->modify('last day of this month');
            $primeroStr = $primero->format('Y-m-d');
            $ultimoStr  = $ultimo->format('Y-m-d');

            // Asignaciones del mes
            $stmt = $conn->prepare(
                "SELECT c.id, c.fecha_asignada, c.id_dieta,
                        d.nombre, d.icono, d.color, d.kcal_objetivo,
                        d.prot_objetivo, d.carb_objetivo, d.grasas_objetivo
                 FROM calendario_asignaciones c
                 JOIN dietas_base d ON d.id = c.id_dieta
                 WHERE c.id_cliente=? AND c.fecha_asignada BETWEEN ? AND ?
                 ORDER BY c.fecha_asignada ASC, c.id ASC"
            );
            $stmt->bind_param('iss', $idCliente, $primeroStr, $ultimoStr);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $asign = [];
            foreach ($rows as $r) {
                $asign[$r['fecha_asignada']][] = [
                    'id'              => (int)$r['id'],
                    'id_dieta'        => (int)$r['id_dieta'],
                    'nombre'          => $r['nombre'],
                    'icono'           => $r['icono'],
                    'color'           => $r['color'] ?: '#2F9E73',
                    'kcal_objetivo'   => (int)$r['kcal_objetivo'],
                    'prot_objetivo'   => (float)$r['prot_objetivo'],
                    'carb_objetivo'   => (float)$r['carb_objetivo'],
                    'grasas_objetivo' => (float)$r['grasas_objetivo'],
                ];
            }

            // Dietas disponibles (solo dietista)
            $dietasDisp = [];
            if ($rol === 'dietista') {
                $stmt = $conn->prepare(
                    "SELECT d.id, d.nombre, d.icono, d.color, d.kcal_objetivo, d.id_cliente
                     FROM dietas_base d
                     WHERE d.id_dietista=?
                       AND (d.id_cliente IS NULL OR d.id_cliente=?)
                     ORDER BY (d.id_cliente IS NULL) ASC, d.nombre ASC"
                );
                $stmt->bind_param('ii', $idDietistaCtx, $idCliente);
                $stmt->execute();
                $rs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                foreach ($rs as $d) {
                    $dietasDisp[] = [
                        'id'             => (int)$d['id'],
                        'nombre'         => $d['nombre'],
                        'icono'          => $d['icono'] ?: '🍽️',
                        'color'          => $d['color'] ?: '#2F9E73',
                        'kcal_objetivo'  => (int)$d['kcal_objetivo'],
                        'id_cliente'     => $d['id_cliente'] !== null ? (int)$d['id_cliente'] : null,
                        'es_plantilla'   => $d['id_cliente'] === null,
                    ];
                }
            }

            // TDEE del cliente (para predicción kg/sem y kg/mes)
            $tdeeData = ['entreno'=>0,'descanso'=>0,'pond'=>0];
            $stmt = $conn->prepare("SELECT * FROM fichas_anamnesis WHERE id_cliente=?");
            $stmt->bind_param('i', $idCliente); $stmt->execute();
            $anam = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($anam) {
                $stmt = $conn->prepare("SELECT peso_kg FROM progresos_metricas WHERE id_cliente=? ORDER BY fecha_hora DESC LIMIT 1");
                $stmt->bind_param('i', $idCliente); $stmt->execute();
                $peso = (float)($stmt->get_result()->fetch_assoc()['peso_kg'] ?? 0);
                $stmt->close();
                $tdeeData = calcular_tdee_calendar($anam, $peso);
            }

            json_out([
                'ok'           => true,
                'mes'          => $mes,
                'primer_dow'   => (int)$primero->format('N'),
                'dias_mes'     => (int)$ultimo->format('j'),
                'today'        => date('Y-m-d'),
                'asignaciones' => (object)$asign,
                'dietas_disp'  => $dietasDisp,
                'tdee' => [
                    'entreno'  => round($tdeeData['entreno']),
                    'descanso' => round($tdeeData['descanso']),
                    'pond'     => round($tdeeData['pond']),
                ],
            ]);
        } break;

        case 'paint': {
            if ($rol !== 'dietista')                  json_out(['ok'=>false,'error'=>'permiso'], 403);
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok'=>false,'error'=>'metodo'],  405);
            if (!csrf_check($_POST['_csrf'] ?? null))  json_out(['ok'=>false,'error'=>'csrf'],    403);

            $idDieta = (int)($_POST['id_dieta'] ?? 0);
            $fechas  = $filtrarFechas($_POST['fechas'] ?? []);

            if ($idDieta <= 0)        json_out(['ok'=>false,'error'=>'id_dieta'], 400);
            if (count($fechas) > 366) json_out(['ok'=>false,'error'=>'demasiadas_fechas'], 400);
            if (empty($fechas))       json_out(['ok'=>true,'items'=>[],'count'=>0]);

            $stmt = $conn->prepare("SELECT id FROM dietas_base WHERE id=? AND id_dietista=?");
            $stmt->bind_param('ii', $idDieta, $idDietistaCtx); $stmt->execute();
            $okD = (bool)$stmt->get_result()->fetch_assoc(); $stmt->close();
            if (!$okD) json_out(['ok'=>false,'error'=>'dieta'], 403);

            $items = [];
            $stmt = $conn->prepare("INSERT IGNORE INTO calendario_asignaciones (id_cliente, fecha_asignada, id_dieta) VALUES (?, ?, ?)");
            foreach ($fechas as $f) {
                $stmt->bind_param('isi', $idCliente, $f, $idDieta);
                $stmt->execute();
                if ($stmt->affected_rows > 0) $items[] = ['fecha'=>$f,'id'=>$stmt->insert_id,'id_dieta'=>$idDieta];
            }
            $stmt->close();
            json_out(['ok'=>true,'items'=>$items,'count'=>count($items)]);
        } break;

        case 'erase': {
            if ($rol !== 'dietista')                  json_out(['ok'=>false,'error'=>'permiso'], 403);
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok'=>false,'error'=>'metodo'],  405);
            if (!csrf_check($_POST['_csrf'] ?? null))  json_out(['ok'=>false,'error'=>'csrf'],    403);

            $fechas = $filtrarFechas($_POST['fechas'] ?? []);
            if (count($fechas) > 366) json_out(['ok'=>false,'error'=>'demasiadas_fechas'], 400);
            if (empty($fechas))       json_out(['ok'=>true,'count'=>0]);

            $count = 0;
            $stmt = $conn->prepare("DELETE FROM calendario_asignaciones WHERE id_cliente=? AND fecha_asignada=?");
            foreach ($fechas as $f) { $stmt->bind_param('is', $idCliente, $f); $stmt->execute(); $count += $stmt->affected_rows; }
            $stmt->close();
            json_out(['ok'=>true,'count'=>$count]);
        } break;

        case 'del_uno': {
            if ($rol !== 'dietista')                  json_out(['ok'=>false,'error'=>'permiso'], 403);
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok'=>false,'error'=>'metodo'],  405);
            if (!csrf_check($_POST['_csrf'] ?? null))  json_out(['ok'=>false,'error'=>'csrf'],    403);

            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) json_out(['ok'=>false,'error'=>'id'], 400);

            $stmt = $conn->prepare("DELETE FROM calendario_asignaciones WHERE id=? AND id_cliente=?");
            $stmt->bind_param('ii', $id, $idCliente); $stmt->execute();
            $af = $stmt->affected_rows; $stmt->close();
            json_out(['ok'=>$af>0]);
        } break;

        case 'clear_all': {
            if ($rol !== 'dietista')                  json_out(['ok'=>false,'error'=>'permiso'], 403);
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok'=>false,'error'=>'metodo'],  405);
            if (!csrf_check($_POST['_csrf'] ?? null))  json_out(['ok'=>false,'error'=>'csrf'],    403);

            $stmt = $conn->prepare("DELETE FROM calendario_asignaciones WHERE id_cliente=?");
            $stmt->bind_param('i', $idCliente); $stmt->execute();
            $count = $stmt->affected_rows; $stmt->close();
            json_out(['ok'=>true,'count'=>$count]);
        } break;

        default: json_out(['ok'=>false,'error'=>'accion_desconocida','detail'=>$accion], 400);
    }

} catch (Throwable $ex) {
    if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
    echo json_encode(['ok'=>false,'error'=>'excepcion','detail'=>$ex->getMessage().' @ '.basename($ex->getFile()).':'.$ex->getLine()]);
}
